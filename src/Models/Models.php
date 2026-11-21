<?php

namespace Sevaske\Zatca\Models;

/**
 * The model classes in use: the package's or your subclasses (config `zatca.models`).
 *
 * @internal
 */
final class Models
{
    /**
     * @return class-string<EgsUnit>
     */
    public static function unit(): string
    {
        return config('zatca.models.unit') ?: EgsUnit::class;
    }

    /**
     * @return class-string<Document>
     */
    public static function document(): string
    {
        return config('zatca.models.document') ?: Document::class;
    }
}
