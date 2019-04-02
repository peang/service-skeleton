<?php

namespace peang\rest;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use models\Role;
use models\User;
use peang\abstraction\DatabaseConnection;
use peang\exceptions\InvalidModelConfigurationException;
use peang\helpers\Helpers;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Exceptions\ValidationException;
use Respect\Validation\Validator;
use Slim\Http\Request;

/**
 * Base Model to use for query
 * @package base\rest
 * @author  Irvan Setiawan <peang.cookie@gmail.com>
 */
abstract class Model extends EloquentModel
{
    /**
     * @var array
     */
    protected $errors = [];

    /**
     * @var string
     */
    public $connectionName = 'default';

    /**
     * @var string
     */
    public $prefix;

    /**
     * Model constructor.
     */
    public function __construct()
    {
        $this->setTable($this->getTableNames());

        self::setConnectionResolver(DatabaseConnection::getConnections()[$this->connectionName]);
        parent::__construct();
    }

    /**
     * @param $primaryKeyValue
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function findOne($primaryKeyValue)
    {
        $primaryKey = self::getPrimaryKey();
        $query = self::query();

        if (Helpers::isArrayAssociative($primaryKeyValue)) {
            foreach ($primaryKeyValue as $k => $v) {
                $query->orWhere($k, '=', $v)->first();
            }
        } else {
            $query->where($primaryKey, '=', $primaryKeyValue);
        }

        return $query->get()->first();
    }

    /**
     * @param $params
     *
     * @return object|static|null
     */
    public static function findOneBy($params)
    {
        $query = self::query();

        foreach ($params as $key => $value) {
            $query->where($key, '=', $value);
        }

        /** @var static $result */
        $result = $query->get()->first();

        if ($result) {
            return $result;
        }

        return $result;
    }

    /**
     * @return mixed
     */
    protected static function getPrimaryKey()
    {
        $classname = get_called_class();

        /** @var static $modelClass */
        $modelClass = new $classname();

        return $modelClass->primaryKey;
    }

    /**
     * @param $primaryKey
     */
    protected function setPrimaryKey($primaryKey)
    {
        $this->primaryKey = $primaryKey;
    }

    /**
     * @return bool
     */
    public function validate()
    {
        $modelRules = $this->getRules();

        $refl = new \ReflectionClass($this);
        $props = $refl->getProperties();
        /** @var \ReflectionProperty $prop */
        foreach ($props as $prop) {
            $validator = new Validator();
            $rules = Helpers::getValue($modelRules, $prop->getName());

            if ($rules) {
                foreach ($rules as $rule) {
                    $validator->addRule($rule);
                }

                try {
                    $validator->check($this->getAttribute($prop->getName()));
                } catch (ValidationException $e) {
                    $template = $e->getTemplate();
                    $params = $e->getParams();

                    $params['name'] = $prop->getName();

                    $this->errors[$prop->getName()] = ValidationException::format($template, $params);
                }
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException('Validation Exception.', 422);
        }

        return true;
    }

    /**
     * @param Request $request
     */
    public function loadAttributes(Request $request)
    {
        $parsedBody = $request->getParsedBody();

        foreach ($parsedBody as $itemName => $itemValue) {
            if ($itemValue !== null) {
                $this->setAttribute($itemName, $itemValue);
            }
        }
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @param array $options
     *
     * @return bool
     * @throws InvalidModelConfigurationException
     */
    public function save(array $options = [])
    {
        $pk = static::getPrimaryKey();
        $prefix = $this->prefix;

        if (!$prefix) {
            throw new InvalidModelConfigurationException("Model has no prefix for OID.");
        }

        if (strlen($prefix) > 4) {
            throw new InvalidModelConfigurationException("Maximum prefix length is 4 chars");
        }

        $oid = str_replace('-', '', Uuid::uuid4()->toString());
        $this->setAttribute($pk, strtoupper(sprintf('%s_%s', $prefix, $oid)));

        return parent::save($options);
    }

    /**
     * @return array
     */
    abstract public function getRules();

    /**
     * @return string
     */
    abstract public function getTableNames();

    /**
     * @param int $page
     * @param int $perPage
     * @param string $sort
     * @param array $filters
     * @return array
     */
    public static function getList($page = 1, $perPage = 10, $sort = null, $filters = [])
    {
        $page = (int)$page;
        $perPage = (int)$perPage;
        $filters = static::splitFilters($filters);

        $query = self::query();

        $query->forPage($page, $perPage);

        if ($filters) {
            foreach ($filters as $filterField => $filterValue) {
                $query->where($filterField, 'like', '%' . $filterValue . '%');
            }    
        }
        // Add filter user by organization id
        $query->where('organization_id', \Api::$user->getAttribute('organization_id'));
        $query->whereKeyNot(\Api::$user->getId());

        if ($sort) {
            if (substr($sort, 0, 1) === '-') {
                $sortString = substr($sort, 1, strlen($sort));

                /** @var Collection $users */
                $query
                    ->orderBy($sortString, 'desc')
                    ->get();
            } else {
                /** @var Collection $users */
                $query
                    ->orderBy($sort)
                    ->get();
            }
        }

        /** @var Collection $users */
        $data = $query->get();
        $totalDataAll = $query->count();

        return [
            'result' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => $query->count(),
                'total' => $totalDataAll
            ]
        ];
    }

    /**
     * @param $filters
     */
    protected static function splitFilters($filters)
    {
        $filterData = explode(';', $filters);
        $filterResult = [];

        if (count($filterData)> 0) {
            foreach ($filterData as $filterString) {
                if ($filterString) {
                    $filter = explode(' ', $filterString);
                    $filterField = Helpers::getValue($filter, 0);
                    // Operator is not yet used
                    $filterOperator = Helpers::getValue($filter, 1);
                    $filterValue = Helpers::getValue($filter, 2);

                    if ($filterValue) {
                        $filterResult[$filterField] = $filterValue;
                    }
                }
            }
        }

        return $filterResult;
    }

}
