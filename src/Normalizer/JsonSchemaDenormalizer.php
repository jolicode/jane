<?php

namespace Joli\Jane\Normalizer;

use Joli\Jane\Reference\Reference;
use Joli\Jane\Reference\Replacer;
use Joli\Jane\Reference\Resolver;
use Joli\Jane\Schema\EmptySchema;
use Joli\Jane\Schema\Schema;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

class JsonSchemaDenormalizer implements DenormalizerInterface
{
    /**
     * @var PropertyAccessor
     */
    private $propertyAccessor;

    public function __construct()
    {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * {@inheritDoc}
     */
    public function denormalize($data, $class, $format = null, array $context = array())
    {
        $replacer = new Replacer(new Resolver());
        $schema   = $this->createSchema($data, $replacer);

        $replacer->replace($schema);

        return $schema;
    }

    /**
     * Create a Schema given a set of data
     *
     * @param $data
     * @param Replacer $replacer
     *
     * @return Reference|EmptySchema|Schema
     */
    protected function createSchema($data, Replacer $replacer)
    {
        $emptySchema = new EmptySchema();
        $schema      = new Schema();

        // Case of empty schema (maybe use a specific class ?)
        if (empty($data)) {
            return clone $emptySchema;
        }

        if (isset($data->{'$ref'})) {
            return new Reference($data->{'$ref'});
        }

        $this->setFields([
            'id', 'title', 'description', 'default', 'multipleOf', 'maximum', 'exclusiveMaximum',
            'minimum', 'exclusiveMinimum', 'maxLength', 'minLength', 'pattern', 'maxItems', 'minItems',
            'uniqueItems', 'maxProperties', 'minProperties', 'required', 'enum', 'type', 'format'
        ], $data, $schema);

        if (isset($data->{'$schema'})) {
            $schema->setSchema($data->{'$schema'});
        }

        $schema->setAdditionalItems(clone $emptySchema);

        if (isset($data->additionalItems)) {
            if (is_object($data->additionalItems)) {
                $schema->setAdditionalItems($this->createSchema($data->additionalItems, $replacer));
            }

            if (is_bool($data->additionalItems)) {
                $schema->setAdditionalItems($data->additionalItems);
            }
        }

        $schema->setItems(clone $emptySchema);

        if (isset($data->items)) {
            if (is_object($data->items)) {
                $schema->setItems($this->createSchema($data->items, $replacer));
            }

            if (is_array($data->items)) {
                $schemaArray = new \ArrayObject([]);

                foreach ($data->items as $item) {
                    $schemaArray[] = $this->createSchema($item, $replacer);
                }

                $schema->setItems($schemaArray);
            }
        }

        $schema->setAdditionalProperties(clone $emptySchema);

        if (isset($data->additionalProperties)) {
            if (is_object($data->additionalProperties)) {
                $schema->setAdditionalItems($this->createSchema($data->additionalProperties, $replacer));
            }

            if (is_bool($data->additionalProperties)) {
                $schema->setAdditionalItems($data->additionalProperties);
            }
        }

        $this->hydrateSchemaArrayAssoc('definitions', $data, $schema, $replacer, clone $emptySchema);
        $this->hydrateSchemaArrayAssoc('properties', $data, $schema, $replacer, clone $emptySchema);
        $this->hydrateSchemaArrayAssoc('patternProperties', $data, $schema, $replacer, clone $emptySchema);

        if (isset($data->dependencies)) {
            $schemaArray = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);

            foreach ($data->dependencies as $key => $dependency) {
                if (is_object($dependency)) {
                    $schemaArray[$key] = $this->createSchema($dependency, $replacer);
                }

                if (is_array($dependency)) {
                    $schemaArray[$key] = $dependency;
                }
            }

            $schema->setDependencies($schemaArray);
        }

        $this->hydrateSchemaArrayAssoc('allOf', $data, $schema, $replacer);
        $this->hydrateSchemaArrayAssoc('anyOf', $data, $schema, $replacer);
        $this->hydrateSchemaArrayAssoc('oneOf', $data, $schema, $replacer);

        if (isset($data->not)) {
            $schema->setNot($this->createSchema($data->not, $replacer));
        }

        return $schema;
    }

    /**
     * Set a value as an array of schema without keys
     *
     * @param $field
     * @param $data
     * @param $schema
     * @param $replacer
     * @param null $default
     */
    protected function hydrateSchemaArray($field, $data, $schema, $replacer, $default = null)
    {
        if (!$this->propertyAccessor->isWritable($schema, $field)) {
            return;
        }

        $this->propertyAccessor->setValue($schema, $field, $default);

        if (isset($data->{$field})) {
            $schemaArray = new \ArrayObject([]);

            foreach ($data->{$field} as $value) {
                $schemaArray[] = $this->createSchema($value, $replacer);
            }

            $this->propertyAccessor->setValue($schema, $field, $schemaArray);
        }
    }

    /**
     * Set a value as an array of schema with keys
     *
     * @param $field
     * @param $data
     * @param $schema
     * @param $replacer
     * @param null $default
     */
    protected function hydrateSchemaArrayAssoc($field, $data, $schema, $replacer, $default = null)
    {
        if (!$this->propertyAccessor->isWritable($schema, $field)) {
            return;
        }

        $this->propertyAccessor->setValue($schema, $field, $default);

        if (isset($data->{$field})) {
            $schemaArray = new \ArrayObject([], \ArrayObject::ARRAY_AS_PROPS);

            foreach ($data->{$field} as $key => $value) {
                $schemaArray[$key] = $this->createSchema($value, $replacer);
            }

            $this->propertyAccessor->setValue($schema, $field, $schemaArray);
        }
    }

    protected function setFields($fields, $data, $schema)
    {
        foreach ($fields as $field) {
            if (isset($data->{$field}) && $this->propertyAccessor->isWritable($schema, $field)) {
                $this->propertyAccessor->setValue($schema, $field, $data->{$field});
            }
        }
    }

    /**
     * Checks whether the given class is supported for denormalization by this normalizer.
     *
     * @param mixed $data Data to denormalize from.
     * @param string $type The class to which the data should be denormalized.
     * @param string $format The format being deserialized from.
     *
     * @return bool
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        if ($type !== Schema::class) {
            return false;
        }

        if ($format !== 'json') {
            return false;
        }

        return true;
    }
}
 