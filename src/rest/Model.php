<?php

namespace peang\rest;

use helpers\Filter;
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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Api;

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
     * @param $op
     * @return mixed
     * @throws \HttpInvalidParamException
     */
    public static function filtersOp($op) {
        $ops = [
            'eq' => '=',
            'neq' => '!=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like'
        ];

        $op = Helpers::getValue($ops, $op, null);
        if ($op === null) {
            throw new InvalidConfigurationException('Unknown Operator');
        }

        return $op;
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
     * @param $params
     *
     * @return object|static|null
     */
    public static function findBy($params)
    {
        $query = self::query();

        foreach ($params as $key => $value) {
            $query->where($key, '=', $value);
        }
        
        /** @var static $result */
        $result = $query->get()->all();

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

        $this->fill($parsedBody);

        return $this;
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return array
     */
    public function addError($attribute, $error)
    {
        return $this->errors[$attribute] = $error;
    }

    /**
     * @param array $options
     * @return string|null
     * @throws InvalidModelConfigurationException
     */
    public function saveModel(array $options = [])
    {
        $pk = static::getPrimaryKey();
        $prefix = $this->prefix;

        if (!$prefix) {
            throw new InvalidModelConfigurationException("Model has no prefix for OID.");
        }

        if (strlen($prefix) > 4) {
            throw new InvalidModelConfigurationException("Maximum prefix length is 4 chars");
        }

        $oid = $this->generateOid();
        $this->setAttribute($pk, $oid);

        if (parent::save($options)) {
            return $oid;
        } else {
            return null;
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function generateOid()
    {
        $oid = str_replace('-', '', Uuid::uuid4()->toString());
        return strtoupper(sprintf('%s_%s', $this->prefix, $oid));
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
    public static function getList($page = 1, $perPage = 10, $sort = null, Filter $filters = null)
    {
        $page = (int)$page;
        $perPage = (int)$perPage;
        
        $query = self::query();
        if ($filters) {
            foreach ($filters->filterData as $filterField => $filter) {
                $query->where($filterField, $filter['op'], $filter['val']);
            }
        }
        // Add filter user by organization id
        if (Api::$user->getRoleId() !== Role::ADMIN_STRING) {
            $query->where('organization_id', Api::$user->getAttribute('organization_id'));
        }
        $query->whereKeyNot(Api::$user->getId());
        
        $totalDataAll = $query->count();

        $query->forPage($page, $perPage);

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

        return [
            'result' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalDataAll
            ]
        ];
    }

}
