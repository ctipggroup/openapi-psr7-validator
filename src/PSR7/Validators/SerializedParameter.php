<?php

declare(strict_types=1);

namespace League\OpenAPIValidation\PSR7\Validators;

use cebe\openapi\spec\Parameter as CebeParameter;
use cebe\openapi\spec\Schema as CebeSchema;
use cebe\openapi\spec\Type as CebeType;
use League\OpenAPIValidation\Schema\Exception\ContentTypeMismatch;
use League\OpenAPIValidation\Schema\Exception\InvalidSchema;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\Exception\TypeMismatch;
use Respect\Validation\Exceptions\ExceptionInterface;
use Respect\Validation\Validator;
use const JSON_ERROR_NONE;
use function explode;
use function in_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_last_error;
use function key;
use function preg_match;
use function reset;
use function strtolower;

final class SerializedParameter
{
    private const STYLE_FORM            = 'form';
    private const STYLE_SPACE_DELIMITED = 'spaceDelimited';
    private const STYLE_PIPE_DELIMITED  = 'pipeDelimited';
    private const STYLE_DELIMITER_MAP   = [
        self::STYLE_FORM => ',',
        self::STYLE_SPACE_DELIMITED => ' ',
        self::STYLE_PIPE_DELIMITED => '|',
    ];

    /** @var CebeSchema */
    private $schema;
    /** @var string|null */
    private $contentType;
    /** @var string|null */
    private $style;
    /** @var bool|null */
    private $explode;

    public function __construct(CebeSchema $schema, ?string $contentType = null, ?string $style = null, ?bool $explode = null)
    {
        $this->schema      = $schema;
        $this->contentType = $contentType;
        $this->style       = $style;
        $this->explode     = $explode;
    }

    public static function fromSpec(CebeParameter $parameter) : self
    {
        $content = $parameter->content;
        try {
            if ($parameter->schema !== null) {
                Validator::not(Validator::notEmpty())->assert($content);

                return new self($parameter->schema, null, $parameter->style, $parameter->explode);
            }

            Validator::length(1, 1)->assert($content);
        } catch (ExceptionInterface $e) {
            // If there is a `schema`, `content` must be empty.
            // If there isn't a `schema`, a `content` with exactly 1 property must exist.
            // @see https://swagger.io/docs/specification/describing-parameters/#schema-vs-content
            throw InvalidSchema::becauseDefensiveSchemaValidationFailed($e);
        }

        $schema      = reset($content)->schema;
        $contentType = key($content);

        return new self($schema, $contentType, $parameter->style, $parameter->explode);
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     *
     * @throws SchemaMismatch
     */
    public function deserialize($value)
    {
        if ($this->isJsonContentType()) {
            // Value MUST be a string.
            if (! is_string($value)) {
                throw TypeMismatch::becauseTypeDoesNotMatch('string', $value);
            }

            $decodedValue = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw ContentTypeMismatch::fromContentType($this->contentType, $value);
            }

            return $decodedValue;
        }

        $value = $this->castToSchemaType($value, $this->schema->type);

        return $value;
    }

    private function isJsonContentType() : bool
    {
        return $this->contentType !== null && preg_match('#^application/.*json$#', $this->contentType) !== false;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function castToSchemaType($value, ?string $type)
    {
        if (($type === CebeType::BOOLEAN) && is_scalar($value) && preg_match('#^(true|false)$#i', (string) $value)) {
            return is_string($value) ? strtolower($value) === 'true' : (bool) $value;
        }

        if (($type === CebeType::NUMBER)
            && is_scalar($value) && is_numeric($value)) {
            return is_int($value) ? (int) $value : (float) $value;
        }

        if (($type === CebeType::INTEGER)
            && is_scalar($value) && ! is_float($value) && preg_match('#^[-+]?\d+$#', (string) $value)) {
            return (int) $value;
        }

        if (($type === CebeType::ARRAY) && is_string($value)) {
            return $this->convertToSerializationStyle($value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function convertToSerializationStyle($value)
    {
        if ($this->explode === false
            && in_array($this->style, [self::STYLE_FORM, self::STYLE_SPACE_DELIMITED, self::STYLE_PIPE_DELIMITED], true)) {
            $value = explode(self::STYLE_DELIMITER_MAP[$this->style], $value);
            foreach ($value as &$val) {
                $val = $this->castToSchemaType($val, $this->schema->items->type ?? null);
            }

            return $value;
        }

        return $value;
    }

    public function getSchema() : CebeSchema
    {
        return $this->schema;
    }
}
