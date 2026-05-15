<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

/**
 * Static facade for declaring schemas. Modeled on the Zod (TypeScript)
 * builder API.
 *
 * ```php
 * use Polidog\Relayer\Validation\Validator;
 *
 * $schema = Validator::object([
 *     'email' => Validator::string()->trim()->email(),
 *     'name'  => Validator::string()->trim()->min(1),
 *     'age'   => Validator::int()->min(0)->optional(),
 * ]);
 *
 * $result = $schema->safeParse($_POST);
 * if ($result->success) {
 *     // $result->data is the coerced array
 * } else {
 *     // $result->errors is ['email' => '...', ...]
 * }
 * ```
 */
final class Validator
{
    public static function string(): StringSchema
    {
        return new StringSchema();
    }

    public static function int(): IntSchema
    {
        return new IntSchema();
    }

    public static function float(): FloatSchema
    {
        return new FloatSchema();
    }

    public static function bool(): BoolSchema
    {
        return new BoolSchema();
    }

    public static function email(?string $message = null): StringSchema
    {
        return (new StringSchema())->trim()->email($message);
    }

    public static function url(?string $message = null): StringSchema
    {
        return (new StringSchema())->trim()->url($message);
    }

    /**
     * @param array<string, Schema> $shape
     */
    public static function object(array $shape): ObjectSchema
    {
        return new ObjectSchema($shape);
    }

    public static function array(Schema $element): ArraySchema
    {
        return new ArraySchema($element);
    }

    /**
     * @param list<string> $values
     */
    public static function enum(array $values): EnumSchema
    {
        return new EnumSchema($values);
    }

    public static function literal(string $value): EnumSchema
    {
        return new EnumSchema([$value]);
    }
}
