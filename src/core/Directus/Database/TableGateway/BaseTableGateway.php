<?php

namespace Directus\Database\TableGateway;

use Directus\Config\StatusMapping;
use Directus\Container\Container;
use Directus\Database\Exception\CollectionHasNotStatusInterface;
use Directus\Database\Exception\DuplicateItemException;
use Directus\Database\Exception\InvalidQueryException;
use Directus\Database\Exception\ItemNotFoundException;
use Directus\Database\Exception\StatusMappingEmptyException;
use Directus\Database\Exception\StatusMappingWrongValueTypeException;
use Directus\Database\Exception\SuppliedArrayAsColumnValue;
use Directus\Database\Query\Builder;
use Directus\Database\Schema\DataTypes;
use Directus\Database\Schema\Object\Collection;
use Directus\Database\RowGateway\BaseRowGateway;
use Directus\Database\Schema\Object\Field;
use Directus\Database\Schema\SchemaManager;
use Directus\Database\TableGatewayFactory;
use Directus\Database\SchemaService;
use Directus\Exception\Exception;
use Directus\Filesystem\Files;
use Directus\Filesystem\Thumbnail;
use Directus\Permissions\Acl;
use Directus\Permissions\Exception\ForbiddenCollectionDeleteException;
use Directus\Permissions\Exception\ForbiddenCollectionUpdateException;
use Directus\Util\ArrayUtils;
use Directus\Util\DateTimeUtils;
use Zend\Db\Adapter\AdapterInterface;
use Zend\Db\Exception\UnexpectedValueException;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\ResultSet\ResultSetInterface;
use Zend\Db\Sql\Ddl;
use Zend\Db\Sql\Delete;
use Zend\Db\Sql\Insert;
use Zend\Db\Sql\Predicate\In;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Sql;
use Zend\Db\Sql\SqlInterface;
use Zend\Db\Sql\Update;
use Zend\Db\TableGateway\Feature;
use Zend\Db\TableGateway\Feature\RowGatewayFeature;
use Zend\Db\TableGateway\TableGateway;

class BaseTableGateway extends TableGateway
{
    public $primaryKeyFieldName = null;

    public $memcache;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * Hook Emitter Instance
     *
     * @var \Directus\Hook\Emitter
     */
    protected static $emitter = null;

    /**
     * @var Container
     */
    protected static $container;

    /**
     * Acl Instance
     *
     * @var Acl|null
     */
    protected $acl = null;

    /**
     * Schema Manager Instance
     *
     * @var SchemaManager|null
     */
    protected $schemaManager = null;

    /**
     * Table Schema Object
     *
     * @var Collection|null
     */
    protected $tableSchema = null;

    /**
     * Name of the field flag that mark a record as hard-delete
     *
     * Note: temporary is being hold by the base table gateway
     *
     * @var string
     */
    protected $deleteFlag = '.delete';

    /**
     * Constructor
     *
     * @param string $table
     * @param AdapterInterface $adapter
     * @param Acl|null $acl
     * @param Feature\AbstractFeature|Feature\FeatureSet|Feature\AbstractFeature[] $features
     * @param ResultSetInterface $resultSetPrototype
     * @param Sql $sql
     * @param string $primaryKeyName
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($table, AdapterInterface $adapter, $acl = null, $features = null, ResultSetInterface $resultSetPrototype = null, Sql $sql = null, $primaryKeyName = null)
    {
        // Add table name reference here, so we can fetch the table schema object
        $this->table = $table;
        $this->acl = $acl;

        // @NOTE: temporary, do we need it here?
        if ($this->primaryKeyFieldName === null) {
            if ($primaryKeyName !== null) {
                $this->primaryKeyFieldName = $primaryKeyName;
            } else {
                $tableObject = $this->getTableSchema();
                if ($tableObject->getPrimaryField()) {
                    $this->primaryKeyFieldName = $tableObject->getPrimaryField()->getName();
                }
            }
        }

        // @NOTE: This will be substituted by a new Cache wrapper class
        // $this->memcache = new MemcacheProvider();
        if ($features === null) {
            $features = new Feature\FeatureSet();
        } else if ($features instanceof Feature\AbstractFeature) {
            $features = [$features];
        } else if (is_array($features)) {
            $features = new Feature\FeatureSet($features);
        }

        $rowGatewayPrototype = new BaseRowGateway($this->primaryKeyFieldName, $table, $adapter, $this->acl);
        $rowGatewayFeature = new RowGatewayFeature($rowGatewayPrototype);
        $features->addFeature($rowGatewayFeature);

        parent::__construct($table, $adapter, $features, $resultSetPrototype, $sql);

        if (static::$container) {
            $this->schemaManager = static::$container->get('schema_manager');
        }
    }

    /**
     * Static Factory Methods
     */

    /**
     * Creates a table gateway based on a table's name
     *
     * Underscore to camelcase table name to namespaced table gateway classname,
     * e.g. directus_users => \Directus\Database\TableGateway\DirectusUsersTableGateway
     *
     * @param string $table
     * @param AdapterInterface $adapter
     * @param null $acl
     *
     * @return RelationalTableGateway
     */
    public static function makeTableGatewayFromTableName($table, $adapter, $acl = null)
    {
        return TableGatewayFactory::create($table, [
            'adapter' => $adapter,
            'acl' => $acl
        ]);
    }

    /**
     * Make a new table gateway
     *
     * @param string $tableName
     * @param AdapterInterface $adapter
     * @param Acl $acl
     *
     * @return BaseTableGateway
     */
    public function makeTable($tableName, $adapter = null, $acl = null)
    {
        $adapter = is_null($adapter) ? $this->adapter : $adapter;
        $acl = is_null($acl) ? $this->acl : $acl;

        return static::makeTableGatewayFromTableName($tableName, $adapter, $acl);
    }

    public function getTableSchema($tableName = null)
    {
        if ($this->tableSchema !== null && ($tableName === null || $tableName === $this->getTable())) {
            return $this->tableSchema;
        }

        if ($tableName === null) {
            $tableName = $this->getTable();
        }

        $skipAcl = $this->acl === null;
        $tableSchema = SchemaService::getCollection($tableName, [], false, $skipAcl);

        if ($tableName === $this->getTable()) {
            $this->tableSchema = $tableSchema;
        }

        return $tableSchema;
    }

    /**
     * Gets the column schema (object)
     *
     * @param $columnName
     * @param null $tableName
     *
     * @return Field
     */
    public function getField($columnName, $tableName = null)
    {
        if ($tableName === null) {
            $tableName = $this->getTable();
        }

        $skipAcl = $this->acl === null;

        return SchemaService::getField($tableName, $columnName, false, $skipAcl);
    }

    /**
     * Gets the status column name
     *
     * @return string
     */
    public function getStatusFieldName()
    {
        return $this->getTableSchema()->getStatusField();
    }

    public function withKey($key, $resultSet)
    {
        $withKey = [];
        foreach ($resultSet as $row) {
            $withKey[$row[$key]] = $row;
        }
        return $withKey;
    }

    /**
     * Create a new row
     *
     * @param null $table
     * @param null $primaryKeyColumn
     *
     * @return BaseRowGateway
     */
    public function newRow($table = null, $primaryKeyColumn = null)
    {
        $table = is_null($table) ? $this->table : $table;
        $primaryKeyColumn = is_null($primaryKeyColumn) ? $this->primaryKeyFieldName : $primaryKeyColumn;
        $row = new BaseRowGateway($primaryKeyColumn, $table, $this->adapter, $this->acl);

        return $row;
    }

    public function find($id, $pk_field_name = null)
    {
        if ($pk_field_name == null) {
            $pk_field_name = $this->primaryKeyFieldName;
        }

        $record = $this->findOneBy($pk_field_name, $id);

        return $record ? $this->parseRecordValuesByType($record) : null;
    }

    public function fetchAll($selectModifier = null)
    {
        return $this->select(function (Select $select) use ($selectModifier) {
            if (is_callable($selectModifier)) {
                $selectModifier($select);
            }
        });
    }

    /**
     * @return array All rows in array form with record IDs for the array's keys.
     */
    public function fetchAllWithIdKeys($selectModifier = null)
    {
        $allWithIdKeys = [];
        $all = $this->fetchAll($selectModifier)->toArray();
        return $this->withKey('id', $all);
    }

    public function findOneBy($field, $value)
    {
        $rowset = $this->ignoreFilters()->select(function (Select $select) use ($field, $value) {
            $select->limit(1);
            $select->where->equalTo($field, $value);
        });

        $row = $rowset->current();
        // Supposing this "one" doesn't exist in the DB
        if (!$row) {
            return false;
        }

        return $row->toArray();
    }

    public function findOneByArray(array $data)
    {
        $rowset = $this->select($data);

        $row = $rowset->current();
        // Supposing this "one" doesn't exist in the DB
        if (!$row) {
            return false;
        }

        return $row->toArray();
    }

    public function addOrUpdateRecordByArray(array $recordData, $collectionName = null)
    {
        $collectionName = is_null($collectionName) ? $this->table : $collectionName;
        $collectionObject = $this->getTableSchema($collectionName);
        foreach ($recordData as $columnName => $columnValue) {
            $fieldObject = $collectionObject->getField($columnName);
            // TODO: Should this be validate in here? should we let the database fails?
            if (($fieldObject && is_array($columnValue) && (!$fieldObject->isJson() && !$fieldObject->isArray()))) {
                // $table = is_null($tableName) ? $this->table : $tableName;
                throw new SuppliedArrayAsColumnValue('Attempting to write an array as the value for column `' . $collectionName . '`.`' . $columnName . '.');
            }
        }

        // @TODO: Dow we need to parse before insert?
        // Commented out because date are not saved correctly in GMT
        // $recordData = $this->parseRecord($recordData);

        $TableGateway = $this->makeTable($collectionName);
        $primaryKey = $TableGateway->primaryKeyFieldName;
        $hasPrimaryKeyData = isset($recordData[$primaryKey]);
        $rowExists = false;
        $currentItem = null;
        $originalFilename = null;

        if ($hasPrimaryKeyData) {
            $select = new Select($collectionName);
            $select->columns(['*']);
            $select->where([
                $primaryKey => $recordData[$primaryKey]
            ]);
            $select->limit(1);
            $result = $TableGateway->ignoreFilters()->selectWith($select);
            $rowExists = $result->count() > 0;
            if ($rowExists) {
                $currentItem = $result->current()->toArray();
            }

            if ($collectionName === SchemaManager::COLLECTION_FILES) {
                $originalFilename = ArrayUtils::get($currentItem, 'filename');
                $recordData = array_merge([
                    'filename' => $originalFilename
                ], $recordData);
            }
        }

        $afterAction = function ($collectionName, $recordData, $replace = false) use ($TableGateway) {
            if ($collectionName == SchemaManager::COLLECTION_FILES && static::$container) {
                $Files = static::$container->get('files');
                $ext = $thumbnailExt = pathinfo($recordData['filename'], PATHINFO_EXTENSION);

                // hotfix: pdf thumbnails are being saved to its original extension
                // file.pdf results into a thumbs/thumb.pdf instead of thumbs/thumb.jpeg
                if (Thumbnail::isNonImageFormatSupported($thumbnailExt)) {
                    $thumbnailExt = Thumbnail::defaultFormat();
                }

                $thumbnailPath = 'thumbs/THUMB_' . $recordData['filename'];
                if ($Files->exists($thumbnailPath)) {
                    $Files->rename($thumbnailPath, 'thumbs/' . $recordData[$this->primaryKeyFieldName] . '.' . $thumbnailExt, $replace);
                }

                $updateArray = [];
                if ($Files->getSettings('file_naming') == 'file_id') {
                    $Files->rename($recordData['filename'], str_pad($recordData[$this->primaryKeyFieldName], 11, '0', STR_PAD_LEFT) . '.' . $ext, $replace);
                    $updateArray['filename'] = str_pad($recordData[$this->primaryKeyFieldName], 11, '0', STR_PAD_LEFT) . '.' . $ext;
                    $recordData['filename'] = $updateArray['filename'];
                }

                if (!empty($updateArray)) {
                    $Update = new Update($collectionName);
                    $Update->set($updateArray);
                    $Update->where([$TableGateway->primaryKeyFieldName => $recordData[$TableGateway->primaryKeyFieldName]]);
                    $TableGateway->updateWith($Update);
                }
            }
        };

        if ($rowExists) {
            $Update = new Update($collectionName);
            $Update->set($recordData);
            $Update->where([
                $primaryKey => $recordData[$primaryKey]
            ]);
            $TableGateway->updateWith($Update);

            if ($collectionName == 'directus_files' && static::$container) {
                if ($originalFilename && $recordData['filename'] !== $originalFilename) {
                    /** @var Files $Files */
                    $Files = static::$container->get('files');
                    $Files->delete(['filename' => $originalFilename]);
                }
            }

            $afterAction($collectionName, $recordData, true);

            $this->runHook('postUpdate', [$TableGateway, $recordData, $this->adapter, null]);
        } else {
            $recordData = $this->applyHook('collection.insert:before', $recordData, [
                'collection_name' => $collectionName
            ]);
            $recordData = $this->applyHook('collection.insert.' . $collectionName . ':before', $recordData);
            $TableGateway->insert($recordData);

            // Only get the last inserted id, if the column has auto increment value
            $columnObject = $this->getTableSchema()->getField($primaryKey);
            if ($columnObject->hasAutoIncrement()) {
                $recordData[$primaryKey] = $TableGateway->getLastInsertValue();
            }

            $afterAction($collectionName, $recordData);

            $this->runHook('postInsert', [$TableGateway, $recordData, $this->adapter, null]);
        }

        $columns = SchemaService::getAllNonAliasCollectionFieldNames($collectionName);
        $recordData = $TableGateway->fetchAll(function ($select) use ($recordData, $columns, $primaryKey) {
            $select
                ->columns($columns)
                ->limit(1);
            $select->where->equalTo($primaryKey, $recordData[$primaryKey]);
        })->current();

        return $recordData;
    }

    public function drop($tableName = null)
    {
        if ($tableName == null) {
            $tableName = $this->table;
        }

        if ($this->acl) {
            $this->acl->enforceAlter($tableName);
        }

        $dropped = false;
        if ($this->schemaManager->tableExists($tableName)) {
            // get drop table query
            $sql = new Sql($this->adapter);
            $drop = new Ddl\DropTable($tableName);
            $query = $sql->buildSqlString($drop);

            $this->runHook('collection.drop:before', [$tableName]);

            $dropped = $this->getAdapter()->query(
                $query
            )->execute();

            $this->runHook('collection.drop', [$tableName]);
            $this->runHook('collection.drop:after', [$tableName]);
        }

        $this->stopManaging();

        return $dropped;
    }

    /**
     * Stop managing a table by removing privileges, preferences columns and table information
     *
     * @param null $tableName
     *
     * @return bool
     */
    public function stopManaging($tableName = null)
    {
        if ($tableName == null) {
            $tableName = $this->table;
        }

        // Remove table privileges
        if ($tableName != SchemaManager::COLLECTION_PERMISSIONS) {
            $privilegesTableGateway = new TableGateway(SchemaManager::COLLECTION_PERMISSIONS, $this->adapter);
            $privilegesTableGateway->delete(['collection' => $tableName]);
        }

        // Remove columns from directus_columns
        $columnsTableGateway = new TableGateway(SchemaManager::COLLECTION_FIELDS, $this->adapter);
        $columnsTableGateway->delete([
            'collection' => $tableName
        ]);

        // Remove table from directus_tables
        $tablesTableGateway = new TableGateway(SchemaManager::COLLECTION_COLLECTIONS, $this->adapter);
        $tablesTableGateway->delete([
            'collection' => $tableName
        ]);

        // Remove table from directus_collection_presets
        $preferencesTableGateway = new TableGateway(SchemaManager::COLLECTION_COLLECTION_PRESETS, $this->adapter);
        $preferencesTableGateway->delete([
            'collection' => $tableName
        ]);

        return true;
    }

    public function dropField($columnName, $tableName = null)
    {
        if ($tableName == null) {
            $tableName = $this->table;
        }

        if ($this->acl) {
            $this->acl->enforceAlter($tableName);
        }

        if (!SchemaService::hasCollectionField($tableName, $columnName, true)) {
            return false;
        }

        // Drop table column if is a non-alias column
        if (!array_key_exists($columnName, array_flip(SchemaService::getAllAliasCollectionFields($tableName, true)))) {
            $sql = new Sql($this->adapter);
            $alterTable = new Ddl\AlterTable($tableName);
            $dropColumn = $alterTable->dropColumn($columnName);
            $query = $sql->getSqlStringForSqlObject($dropColumn);

            $this->adapter->query(
                $query
            )->execute();
        }

        // Remove column from directus_columns
        $columnsTableGateway = new TableGateway(SchemaManager::COLLECTION_FIELDS, $this->adapter);
        $columnsTableGateway->delete([
            'table_name' => $tableName,
            'column_name' => $columnName
        ]);

        // Remove column from sorting column in directus_preferences
        $preferencesTableGateway = new TableGateway(SchemaManager::COLLECTION_COLLECTION_PRESETS, $this->adapter);
        $preferencesTableGateway->update([
            'sort' => $this->primaryKeyFieldName,
            'sort_order' => 'ASC'
        ], [
            'table_name' => $tableName,
            'sort' => $columnName
        ]);

        return true;
    }

    /*
      Temporary solutions to fix add column error
        This add column is the same old-db add_column method
    */
    public function addColumn($tableName, $tableData)
    {
        // @TODO: enforce permission
        $directus_types = ['MANYTOMANY', 'ONETOMANY', 'ALIAS'];
        $relationshipType = ArrayUtils::get($tableData, 'relationship_type', null);
        // TODO: list all types which need manytoone ui
        // Hard-coded
        $manytoones = ['single_file', 'many_to_one', 'many_to_one_typeahead', 'MANYTOONE'];

        if (!in_array($relationshipType, $directus_types)) {
            $this->addTableColumn($tableName, $tableData);
            // Temporary solutions to #481, #645
            if (array_key_exists('ui', $tableData) && in_array($tableData['ui'], $manytoones)) {
                $tableData['relationship_type'] = 'MANYTOONE';
                $tableData['junction_key_right'] = $tableData['column_name'];
            }
        }

        //This is a 'virtual column'. Write to directus schema instead of MYSQL
        $this->addVirtualColumn($tableName, $tableData);

        return $tableData['column_name'];
    }

    // @TODO: TableGateway should not be handling table creation
    protected function addTableColumn($tableName, $columnData)
    {
        $column_name = $columnData['column_name'];
        $dataType = $columnData['data_type'];
        $comment = $this->getAdapter()->getPlatform()->quoteValue(ArrayUtils::get($columnData, 'comment', ''));

        if (array_key_exists('length', $columnData)) {
            $charLength = $columnData['length'];
            // SET and ENUM data type has its values in the char_length attribute
            // each value are separated by commas
            // it must be wrap into quotes
            if (!$this->schemaManager->isFloatingPointType($dataType) && strpos($charLength, ',') !== false) {
                $charLength = implode(',', array_map(function ($value) {
                    return '"' . trim($value) . '"';
                }, explode(',', $charLength)));
            }

            $dataType = $dataType . '(' . $charLength . ')';
        }

        $default = '';
        if (ArrayUtils::get($columnData, 'default_value')) {
            $value = ArrayUtils::get($columnData, 'default_value');
            $length = ArrayUtils::get($columnData, 'length');
            $defaultValue = $this->schemaManager->castDefaultValue($value, $dataType, $length);

            $default = ' DEFAULT ' . (is_string($defaultValue) ? sprintf('"%s"', $defaultValue) : $defaultValue);
        }

        // TODO: wrap this into an abstract DDL class
        $sql = 'ALTER TABLE `' . $tableName . '` ADD COLUMN `' . $column_name . '` ' . $dataType . $default . ' COMMENT "' . $comment . '"';

        $this->adapter->query($sql)->execute();
    }

    protected function addVirtualColumn($tableName, $columnData)
    {
        $alias_columns = ['table_name', 'column_name', 'data_type', 'related_table', 'junction_table', 'junction_key_left', 'junction_key_right', 'sort', 'ui', 'comment', 'relationship_type'];

        $columnData['table_name'] = $tableName;
        // NOTE: setting 9999 as default just because
        $columnData['sort'] = ArrayUtils::get($columnData, 'sort', 9999);

        $data = array_intersect_key($columnData, array_flip($alias_columns));
        return $this->addOrUpdateRecordByArray($data, 'directus_columns');
    }

    public function castFloatIfNumeric(&$value, $key)
    {
        if ($key != 'table_name') {
            $value = is_numeric($value) ? (float)$value : $value;
        }
    }

    /**
     * Convenience method for dumping a ZendDb Sql query object as debug output.
     *
     * @param  SqlInterface $query
     *
     * @return null
     */
    public function dumpSql(SqlInterface $query)
    {
        $sql = new Sql($this->adapter);
        $query = $sql->getSqlStringForSqlObject($query, $this->adapter->getPlatform());
        return $query;
    }

    public function ignoreFilters()
    {
        $this->options['filter'] = false;

        return $this;
    }

    /**
     * @param Select $select
     *
     * @return ResultSet
     *
     * @throws \Directus\Permissions\Exception\ForbiddenFieldReadException
     * @throws \Directus\Permissions\Exception\ForbiddenFieldWriteException
     * @throws \Exception
     */
    protected function executeSelect(Select $select)
    {
        $useFilter = ArrayUtils::get($this->options, 'filter', true) !== false;
        unset($this->options['filter']);

        if ($this->acl) {
            $this->enforceSelectPermission($select);
        }

        $selectState = $select->getRawState();
        $selectCollectionName = $selectState['table'];

        if ($useFilter) {
            $selectState = $this->applyHooks([
                'collection.select:before',
                'collection.select.' . $selectCollectionName . ':before',
            ], $selectState, [
                'collection_name' => $selectCollectionName
            ]);

            // NOTE: This can be a "dangerous" hook, so for now we only support columns
            $select->columns(ArrayUtils::get($selectState, 'columns', ['*']));
        }

        try {
            $result = parent::executeSelect($select);
        } catch (UnexpectedValueException $e) {
            throw new InvalidQueryException(
                $this->dumpSql($select),
                $e
            );
        }

        if ($useFilter) {
            $result = $this->applyHooks([
                'collection.select',
                'collection.select.' . $selectCollectionName
            ], $result, [
                'selectState' => $selectState,
                'collection_name' => $selectCollectionName
            ]);
        }

        return $result;
    }

    /**
     * @param Insert $insert
     *
     * @return mixed
     *
     * @throws \Directus\Database\Exception\InvalidQueryException
     */
    protected function executeInsert(Insert $insert)
    {
        if ($this->acl) {
            $this->enforceInsertPermission($insert);
        }

        $insertState = $insert->getRawState();
        $insertTable = $this->getRawTableNameFromQueryStateTable($insertState['table']);
        $insertData = $insertState['values'];
        // Data to be inserted with the column name as assoc key.
        $insertDataAssoc = array_combine($insertState['columns'], $insertData);

        $this->runHook('collection.insert:before', [$insertTable, $insertDataAssoc]);
        $this->runHook('collection.insert.' . $insertTable . ':before', [$insertDataAssoc]);

        try {
            $result = parent::executeInsert($insert);
        } catch (UnexpectedValueException $e) {
            if (
                strtolower($this->adapter->platform->getName()) === 'mysql'
                && strpos(strtolower($e->getMessage()), 'duplicate entry') !== false
            ) {
                preg_match("/Duplicate entry '([^']+)' for key '([^']+)'/i", $e->getMessage(), $output);

                if ($output) {
                    throw new DuplicateItemException($this->table, $output[1]);
                }
            }

            throw new InvalidQueryException(
                $this->dumpSql($insert),
                $e
            );
        }

        $insertTableGateway = $this->makeTable($insertTable);

        // hotfix: directus_tables does not have auto generated value primary key
        if ($this->getTable() === SchemaManager::COLLECTION_COLLECTIONS) {
            $generatedValue = ArrayUtils::get($insertDataAssoc, $this->primaryKeyFieldName, 'table_name');
        } else {
            $generatedValue = $this->getLastInsertValue();
        }

        $resultData = $insertTableGateway->find($generatedValue);

        $this->runHook('collection.insert', [$insertTable, $resultData]);
        $this->runHook('collection.insert.' . $insertTable, [$resultData]);
        $this->runHook('collection.insert:after', [$insertTable, $resultData]);
        $this->runHook('collection.insert.' . $insertTable . ':after', [$resultData]);

        return $result;
    }

    /**
     * @param Update $update
     *
     * @return mixed
     *
     * @throws \Directus\Database\Exception\InvalidQueryException
     */
    protected function executeUpdate(Update $update)
    {
        $useFilter = ArrayUtils::get($this->options, 'filter', true) !== false;
        unset($this->options['filter']);

        if ($this->acl) {
            $this->enforceUpdatePermission($update);
        }

        $updateState = $update->getRawState();
        $updateTable = $this->getRawTableNameFromQueryStateTable($updateState['table']);
        $updateData = $updateState['set'];

        if ($useFilter) {
            $updateData = $this->runBeforeUpdateHooks($updateTable, $updateData);
        }

        $update->set($updateData);

        try {
            $result = parent::executeUpdate($update);
        } catch (UnexpectedValueException $e) {
            throw new InvalidQueryException(
                $this->dumpSql($update),
                $e
            );
        }

        if ($useFilter) {
            $this->runAfterUpdateHooks($updateTable, $updateData);
        }

        return $result;
    }

    /**
     * @param Delete $delete
     *
     * @return mixed
     *
     * @throws \Directus\Database\Exception\InvalidQueryException
     */
    protected function executeDelete(Delete $delete)
    {
        $ids = [];

        if ($this->acl) {
            $this->enforceDeletePermission($delete);
        }

        $deleteState = $delete->getRawState();
        $deleteTable = $this->getRawTableNameFromQueryStateTable($deleteState['table']);

        // Runs select PK with passed delete's $where before deleting, to use those for the even hook
        if ($pk = $this->primaryKeyFieldName) {
            $select = $this->sql->select();
            $select->where($deleteState['where']);
            $select->columns([$pk]);
            $results = parent::executeSelect($select);

            foreach($results as $result) {
                $ids[] = $result['id'];
            }
        }

        // skipping everything, if there is nothing to delete
        if ($ids) {
            $delete = $this->sql->delete();
            $expression = new In($pk, $ids);
            $delete->where($expression);

            foreach ($ids as $id) {
                $deleteData = ['id' => $id];
                $this->runHook('collection.delete:before', [$deleteTable, $deleteData]);
                $this->runHook('collection.delete.' . $deleteTable . ':before', [$deleteData]);
            }

            try {
                $result = parent::executeDelete($delete);
            } catch (UnexpectedValueException $e) {
                throw new InvalidQueryException(
                    $this->dumpSql($delete),
                    $e
                );
            }

            foreach ($ids as $id) {
                $deleteData = ['id' => $id];
                $this->runHook('collection.delete', [$deleteTable, $deleteData]);
                $this->runHook('collection.delete:after', [$deleteTable, $deleteData]);
                $this->runHook('collection.delete.' . $deleteTable, [$deleteData]);
                $this->runHook('collection.delete.' . $deleteTable . ':after', [$deleteData]);
            }

            return $result;
        }
    }

    protected function getRawTableNameFromQueryStateTable($table)
    {
        if (is_string($table)) {
            return $table;
        }

        if (is_array($table)) {
            // The only value is the real table name (key is alias).
            return array_pop($table);
        }

        throw new \InvalidArgumentException('Unexpected parameter of type ' . get_class($table));
    }

    /**
     * Convert dates to ISO 8601 format
     *
     * @param array $records
     * @param Collection $tableSchema
     * @param null $tableName
     *
     * @return array|mixed
     */
    public function convertDates(array $records, Collection $tableSchema, $tableName = null)
    {
        $tableName = $tableName === null ? $this->table : $tableName;
        $isCustomTable = !$this->schemaManager->isDirectusCollection($tableName);
        $hasSystemDateColumn = $this->schemaManager->hasSystemDateField($tableName);

        if (!$hasSystemDateColumn && $isCustomTable) {
            return $records;
        }

        // ==========================================================================
        // hotfix: records sometimes are no set as an array of rows.
        // NOTE: this code is duplicate @see: AbstractSchema::parseRecordValuesByType
        // ==========================================================================
        $singleRecord = false;
        if (!ArrayUtils::isNumericKeys($records)) {
            $records = [$records];
            $singleRecord = true;
        }

        foreach ($records as $index => $row) {
            foreach ($tableSchema->getFields() as $column) {
                $canConvert = in_array(strtolower($column->getType()), ['timestamp', 'datetime']);
                // Directus convert all dates to ISO to all datetime columns in the core tables
                // and any columns using system date interfaces (date_created or date_modified)
                if ($isCustomTable && !$column->isSystemDate()) {
                    $canConvert = false;
                }

                if ($canConvert) {
                    $columnName = $column->getName();

                    if (isset($row[$columnName])) {
                        $datetime = DateTimeUtils::createFromDefaultFormat($row[$columnName], 'UTC');
                        $datetime->switchToTimeZone(get_user_timezone());
                        $records[$index][$columnName] = $datetime->toISO8601Format();
                    }
                }
            }
        }

        return $singleRecord ? reset($records) : $records;
    }

    /**
     * Parse records value by its column type
     *
     * @param array $records
     * @param null $tableName
     *
     * @return array
     */
    protected function parseRecordValuesByType(array $records, $tableName = null)
    {
        // NOTE: Performance spot
        $tableName = $tableName === null ? $this->table : $tableName;
        // Get the columns directly from the source
        // otherwise will keep in a circle loop loading Acl Instances
        $columns = SchemaService::getSchemaManagerInstance()->getFields($tableName);

        return $this->schemaManager->castRecordValues($records, $columns);
    }

    /**
     * Parse Records values (including format date by ISO 8601) by its column type
     *
     * @param $records
     * @param null $tableName
     *
     * @return array|mixed
     */
    public function parseRecord($records, $tableName = null)
    {
        // NOTE: Performance spot
        if (is_array($records)) {
            $tableName = $tableName === null ? $this->table : $tableName;
            $records = $this->parseRecordValuesByType($records, $tableName);
            $tableSchema = $this->getTableSchema($tableName);
            $records = $this->convertDates($records, $tableSchema, $tableName);
        }

        return $records;
    }

    /**
     * Enforce permission on Select
     *
     * @param Select $select
     *
     * @throws \Exception
     */
    protected function enforceSelectPermission(Select $select)
    {
        $selectState = $select->getRawState();
        $table = $this->getRawTableNameFromQueryStateTable($selectState['table']);

        // @TODO: enforce view permission

        // Enforce field read blacklist on Select's main table
        try {
            // @TODO: Enforce must return a list of columns without the blacklist
            // when asterisk (*) is used
            // and only throw and error when all the selected columns are blacklisted
            $this->acl->enforceReadField($table, $selectState['columns']);
        } catch (\Exception $e) {
            if ($selectState['columns'][0] != '*') {
                throw $e;
            }

            $selectState['columns'] = SchemaService::getAllNonAliasCollectionFieldsName($table);
            $this->acl->enforceReadField($table, $selectState['columns']);
        }

        // Enforce field read blacklist on Select's join tables
        foreach ($selectState['joins'] as $join) {
            $joinTable = $this->getRawTableNameFromQueryStateTable($join['name']);
            $this->acl->enforceReadField($joinTable, $join['columns']);
        }
    }

    /**
     * Enforce permission on Insert
     *
     * @param Insert $insert
     *
     * @throws \Exception
     */
    public function enforceInsertPermission(Insert $insert)
    {
        $insertState = $insert->getRawState();
        $insertTable = $this->getRawTableNameFromQueryStateTable($insertState['table']);

        $statusValue = null;
        $statusField = $this->getTableSchema()->getStatusField();
        if ($statusField) {
            $valueKey = array_search($statusField->getName(), $insertState['columns']);
            if ($valueKey !== false) {;
                $statusValue = ArrayUtils::get($insertState['values'], $valueKey);
            } else {
                $statusValue = $statusField->getDefaultValue();
            }
        }

        $this->acl->enforceCreate($insertTable, $statusValue);
    }

    /**
     * @param Builder $builder
     */
    protected function enforceReadPermission(Builder $builder)
    {
        // ----------------------------------------------------------------------------
        // Make sure the user has permission to at least their items
        // ----------------------------------------------------------------------------
        $this->acl->enforceReadOnce($this->table);
        $collectionObject = $this->getTableSchema();
        $userCreatedField = $collectionObject->getUserCreateField();
        $statusField = $collectionObject->getStatusField();

        // If there's not user created interface, user must have full read permission
        if (!$userCreatedField && !$statusField) {
            $this->acl->enforceReadAll($this->table);
            return;
        }

        // User can read all items, nothing else to check
        if ($this->acl->canReadAll($this->table)) {
            return;
        }

        $groupUsersId = get_user_ids_in_group($this->acl->getGroupId());
        $authenticatedUserId = $this->acl->getUserId();
        $statuses = $this->acl->getCollectionStatuses($this->table);

        if (empty($statuses)) {
            $ownerIds = [$authenticatedUserId];
            if ($this->acl->canReadFromGroup($this->table)) {
                $ownerIds = array_merge(
                    $ownerIds,
                    $groupUsersId
                );
            }

            $builder->whereIn($userCreatedField->getName(), $ownerIds);
        } else {
            $collection = $this->table;
            $builder->nestWhere(function (Builder $builder) use ($collection, $statuses, $statusField, $userCreatedField, $groupUsersId, $authenticatedUserId) {
                foreach ($statuses as $status) {
                    $canReadAll = $this->acl->canReadAll($collection, $status);
                    $canReadMine = $this->acl->canReadMine($collection, $status);

                    if ((!$canReadAll && !$userCreatedField) || !$canReadMine) {
                        continue;
                    }

                    $ownerIds = $canReadAll ? null : [$authenticatedUserId];
                    $canReadFromGroup = $this->acl->canReadFromGroup($collection, $status);
                    if (!$canReadAll && $canReadFromGroup) {
                        $ownerIds = array_merge(
                            $ownerIds,
                            $groupUsersId
                        );
                    }

                    $builder->nestOrWhere(function (Builder $builder) use ($statuses, $ownerIds, $statusField, $userCreatedField, $status) {
                        if ($ownerIds) {
                            $builder->whereIn($userCreatedField->getName(), $ownerIds);
                        }

                        $builder->whereEqualTo($statusField->getName(), $status);
                    });
                }


            });
        }
    }

    /**
     * Enforce permission on Update
     *
     * @param Update $update
     *
     * @throws \Exception
     */
    public function enforceUpdatePermission(Update $update)
    {
        if ($this->acl->canUpdateAll($this->table)) {
            return;
        }

        $collectionObject = $this->getTableSchema();
        $currentUserId = $this->acl->getUserId();
        $currentGroupId = $this->acl->getGroupId();
        $updateState = $update->getRawState();
        $updateTable = $this->getRawTableNameFromQueryStateTable($updateState['table']);
        $select = $this->sql->select();
        $select->where($updateState['where']);
        $select->limit(1);
        $item = $this->ignoreFilters()->selectWith($select)->toArray();
        $item = reset($item);
        $statusId = null;

        // Item not found, item cannot be updated
        if (!$item) {
            throw new ForbiddenCollectionUpdateException($updateTable);
        }

        // Enforce write field blacklist
        $this->acl->enforceWriteField($updateTable, array_keys($updateState['set']));

        if ($collectionObject->hasStatusField()) {
            $statusField = $this->getTableSchema()->getStatusField();
            $statusId = $item[$statusField->getName()];
        }

        // User Created Interface not found, item cannot be updated
        $itemOwnerField = $this->getTableSchema()->getUserCreateField();
        if (!$itemOwnerField) {
            $this->acl->enforceUpdateAll($updateTable, $statusId);
            return;
        }

        // Owner not found, item cannot be updated
        $owner = get_item_owner($updateTable, $item[$collectionObject->getPrimaryKeyName()]);
        if (!is_array($owner)) {
            throw new ForbiddenCollectionUpdateException($updateTable);
        }

        $userItem = $currentUserId === $owner['id'];
        $groupItem = $currentGroupId === $owner['group'];
        if (!$userItem && !$groupItem && !$this->acl->canUpdateAll($updateTable, $statusId)) {
            throw new ForbiddenCollectionUpdateException($updateTable);
        }

        if (!$userItem && $groupItem) {
            $this->acl->enforceUpdateFromGroup($updateTable, $statusId);
        } else if ($userItem) {
            $this->acl->enforceUpdate($updateTable, $statusId);
        }
    }

    /**
     * Enforce permission on Delete
     *
     * @param Delete $delete
     *
     * @throws ForbiddenCollectionDeleteException
     */
    public function enforceDeletePermission(Delete $delete)
    {
        $collectionObject = $this->getTableSchema();
        $currentUserId = $this->acl->getUserId();
        $currentGroupId = $this->acl->getGroupId();
        $deleteState = $delete->getRawState();
        $deleteTable = $this->getRawTableNameFromQueryStateTable($deleteState['table']);
        // $cmsOwnerColumn = $this->acl->getCmsOwnerColumnByTable($deleteTable);
        // $canBigDelete = $this->acl->hasTablePrivilege($deleteTable, 'bigdelete');
        // $canDelete = $this->acl->hasTablePrivilege($deleteTable, 'delete');
        // $aclErrorPrefix = $this->acl->getErrorMessagePrefix();

        $select = $this->sql->select();
        $select->where($deleteState['where']);
        $select->limit(1);
        $item = $this->ignoreFilters()->selectWith($select)->toArray();
        $item = reset($item);
        $statusId = null;

        // Item not found, item cannot be updated
        if (!$item) {
            throw new ItemNotFoundException();
        }

        if ($collectionObject->hasStatusField()) {
            $statusField = $this->getTableSchema()->getStatusField();
            $statusId = $item[$statusField->getName()];
        }

        // User Created Interface not found, item cannot be updated
        $itemOwnerField = $this->getTableSchema()->getUserCreateField();
        if (!$itemOwnerField) {
            $this->acl->enforceDeleteAll($deleteTable, $statusId);
            return;
        }

        // Owner not found, item cannot be updated
        $owner = get_item_owner($deleteTable, $item[$collectionObject->getPrimaryKeyName()]);
        if (!is_array($owner)) {
            throw new ForbiddenCollectionDeleteException($deleteTable);
        }

        $userItem = $currentUserId === $owner['id'];
        $groupItem = $currentGroupId === $owner['group'];
        if (!$userItem && !$groupItem && !$this->acl->canDeleteAll($deleteTable, $statusId)) {
            throw new ForbiddenCollectionDeleteException($deleteTable);
        }

        if (!$userItem && $groupItem) {
            $this->acl->enforceDeleteFromGroup($deleteTable, $statusId);
        } else if ($userItem) {
            $this->acl->enforceDelete($deleteTable, $statusId);
        }

        // @todo: clean way
        // @TODO: this doesn't need to be bigdelete
        //        the user can only delete their own entry
        // if ($deleteTable === 'directus_bookmarks') {
        //     $canBigDelete = true;
        // }

        // @TODO: Update conditions
        // =============================================================================
        // Cannot delete if there's no magic owner column and can't big delete
        // All deletes are "big" deletes if there is no magic owner column.
        // =============================================================================
        // if (false === $cmsOwnerColumn && !$canBigDelete) {
        //     throw new ForbiddenCollectionDeleteException($aclErrorPrefix . 'The table `' . $deleteTable . '` is missing the `user_create_column` within `directus_collections` (BigHardDelete Permission Forbidden)');
        // } else if (!$canBigDelete) {
        //     // Who are the owners of these rows?
        //     list($predicateResultQty, $predicateOwnerIds) = $this->acl->getCmsOwnerIdsByTableGatewayAndPredicate($this, $deleteState['where']);
        //     if (!in_array($currentUserId, $predicateOwnerIds)) {
        //         //   $exceptionMessage = "Table harddelete access forbidden on $predicateResultQty `$deleteTable` table records owned by the authenticated CMS user (#$currentUserId).";
        //         $groupsTableGateway = $this->makeTable('directus_groups');
        //         $group = $groupsTableGateway->find($this->acl->getGroupId());
        //         $exceptionMessage = '[' . $group['name'] . '] permissions only allow you to [delete] your own items.';
        //         //   $aclErrorPrefix = $this->acl->getErrorMessagePrefix();
        //         throw new  ForbiddenCollectionDeleteException($exceptionMessage);
        //     }
        // }
    }

    /**
     * Get the column identifier with the specific quote and table prefixed
     *
     * @param string $column
     * @param string|null $table
     *
     * @return string
     */
    public function getColumnIdentifier($column, $table = null)
    {
        $platform = $this->getAdapter()->getPlatform();

        // TODO: find a common place to share this code
        // It is a duplicated code from Builder.php
        if (strpos($column, $platform->getIdentifierSeparator()) === false) {
            $column = implode($platform->getIdentifierSeparator(), [$table, $column]);
        }

        return $column;
    }

    /**
     * Get the column name from the identifier
     *
     * @param string $column
     *
     * @return string
     */
    public function getColumnFromIdentifier($column)
    {
        $platform = $this->getAdapter()->getPlatform();

        // TODO: find a common place to share this code
        // It is duplicated code in Builder.php
        if (strpos($column, $platform->getIdentifierSeparator()) !== false) {
            $identifierParts = explode($platform->getIdentifierSeparator(), $column);
            $column = array_pop($identifierParts);
        }

        return $column;
    }

    /**
     * Get the table name from the identifier
     *
     * @param string $column
     * @param string|null $table
     *
     * @return string
     */
    public function getTableFromIdentifier($column, $table = null)
    {
        $platform = $this->getAdapter()->getPlatform();

        if ($table === null) {
            $table = $this->getTable();
        }

        // TODO: find a common place to share this code
        // It is duplicated code in Builder.php
        if (strpos($column, $platform->getIdentifierSeparator()) !== false) {
            $identifierParts = explode($platform->getIdentifierSeparator(), $column);
            $table = array_shift($identifierParts);
        }

        return $table;
    }

    /**
     * Gets schema manager
     *
     * @return SchemaManager|null
     */
    public function getSchemaManager()
    {
        return $this->schemaManager;
    }

    /**
     * Set application container
     *
     * @param $container
     */
    public static function setContainer($container)
    {
        static::$container = $container;
    }

    /**
     * @return Container
     */
    public static function getContainer()
    {
        return static::$container;
    }

    public static function setHookEmitter($emitter)
    {
        static::$emitter = $emitter;
    }

    public function runHook($name, $args = null)
    {
        if (static::$emitter) {
            static::$emitter->execute($name, $args);
        }
    }

    /**
     * Apply a list of hook against the given data
     *
     * @param array $names
     * @param null $data
     * @param array $attributes
     *
     * @return array|\ArrayObject|null
     */
    public function applyHooks(array $names, $data = null, array $attributes = [])
    {
        foreach ($names as $name) {
            $data = $this->applyHook($name, $data, $attributes);
        }

        return $data;
    }

    /**
     * Apply hook against the given data
     *
     * @param $name
     * @param null $data
     * @param array $attributes
     *
     * @return \ArrayObject|array|null
     */
    public function applyHook($name, $data = null, array $attributes = [])
    {
        // TODO: Ability to run multiple hook names
        // $this->applyHook('hook1,hook2');
        // $this->applyHook(['hook1', 'hook2']);
        // ----------------------------------------------------------------------------
        // TODO: Move this to a separate class to handle common events
        // $this->applyNewRecord($table, $record);
        if (static::$emitter && static::$emitter->hasFilterListeners($name)) {
            $isResultSet = $data instanceof ResultSetInterface;
            $resultSet = null;

            if ($isResultSet) {
                $resultSet = $data;
                $data = $resultSet->toArray();
            }

            $data = static::$emitter->apply($name, $data, $attributes);

            if ($isResultSet && $resultSet) {
                $data = new \ArrayObject($data);
                $resultSet->initialize($data->getIterator());
                $data = $resultSet;
            }
        }

        return $data;
    }

    /**
     * Run before table update hooks and filters
     *
     * @param string $updateCollectionName
     * @param array $updateData
     *
     * @return array|\ArrayObject
     */
    protected function runBeforeUpdateHooks($updateCollectionName, $updateData)
    {
        // Filters
        $updateData = $this->applyHook('collection.update:before', $updateData, [
            'collection_name' => $updateCollectionName
        ]);
        $updateData = $this->applyHook('collection.update.' . $updateCollectionName . ':before', $updateData);

        // Hooks
        $this->runHook('collection.update:before', [$updateCollectionName, $updateData]);
        $this->runHook('collection.update.' . $updateCollectionName . ':before', [$updateData]);

        return $updateData;
    }

    /**
     * Run after table update hooks and filters
     *
     * @param string $updateTable
     * @param string $updateData
     */
    protected function runAfterUpdateHooks($updateTable, $updateData)
    {
        $this->runHook('collection.update', [$updateTable, $updateData]);
        $this->runHook('collection.update:after', [$updateTable, $updateData]);
        $this->runHook('collection.update.' . $updateTable, [$updateData]);
        $this->runHook('collection.update.' . $updateTable . ':after', [$updateData]);
    }

    /**
     * Gets Directus settings (from DB)
     *
     * @param null $key
     *
     * @return mixed
     */
    public function getSettings($key = null)
    {
        $settings = [];

        if (static::$container) {
            $settings = static::$container->get('app.settings');
        }

        return $key !== null ? ArrayUtils::get($settings, $key) : $settings;
    }

    /**
     * Get the table statuses
     *
     * @return array
     */
    public function getAllStatuses()
    {
        $statuses = [];
        $statusMapping = $this->getStatusMapping();

        if ($statusMapping) {
            $statuses = $statusMapping->getAllStatusesValue();
        }

        return $statuses;
    }

    /**
     * Gets the table published statuses
     *
     * @return array
     */
    public function getPublishedStatuses()
    {
        return $this->getStatuses('published');
    }

    /**
     * Gets the table statuses with the given type
     *
     * @param $type
     *
     * @return array
     */
    protected function getStatuses($type)
    {
        $statuses = [];
        $statusMapping = $this->getStatusMapping();

        if ($statusMapping) {
            switch ($type) {
                case 'published':
                    $statuses = $statusMapping->getPublishedStatusesValue();
                    break;
            }
        }

        return $statuses;
    }

    /**
     * Gets the collection status mapping
     *
     * @return StatusMapping|null
     *
     * @throws CollectionHasNotStatusInterface
     * @throws Exception
     */
    protected function getStatusMapping()
    {
        if (!$this->getTableSchema()->hasStatusField()) {
            throw new CollectionHasNotStatusInterface($this->table);
        }

        $collectionStatusMapping = $this->getTableSchema()->getStatusMapping();
        if (!$collectionStatusMapping) {
            if (!static::$container) {
                throw new Exception('collection status interface is missing status mapping and the system was unable to find the global status mapping');
            }

            $collectionStatusMapping = static::$container->get('status_mapping');
        }

        $this->validateStatusMapping($collectionStatusMapping);

        return $collectionStatusMapping;
    }

    /**
     * Validates a status mapping against the field type
     *
     * @param StatusMapping $statusMapping
     *
     * @throws CollectionHasNotStatusInterface
     * @throws StatusMappingEmptyException
     * @throws StatusMappingWrongValueTypeException
     */
    protected function validateStatusMapping(StatusMapping $statusMapping)
    {
        if ($statusMapping->isEmpty()) {
            throw new StatusMappingEmptyException($this->table);
        }

        $statusField = $this->getTableSchema()->getStatusField();
        if (!$statusField) {
            throw new CollectionHasNotStatusInterface($this->table);
        }

        $type = 'string';
        if (DataTypes::isNumericType($statusField->getType())) {
            $type = 'numeric';
        }

        foreach ($statusMapping as $status) {
            if (!call_user_func('is_' . $type, $status->getValue())) {
                throw new StatusMappingWrongValueTypeException($type, $statusField->getName(), $this->table);
            }
        }
    }
}
