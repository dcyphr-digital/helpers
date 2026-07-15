<?php

namespace DcyphrDigital\Helpers\Support;

use App\Connectors\Incoming\Website\Product\Category;
use App\Models\Website\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait WebsiteProductHelper
{
    public function extractProductsCategories(?Collection $products, ?array $allowedAttributeKeys = null): Collection
    {
        $categories = collect();
        $products?->each(function (Product $product) use (&$categories, $allowedAttributeKeys) {
            $categories = $categories->merge($this->extractCategories(product: $product, allowedAttributeKeys: $allowedAttributeKeys)->all());
        });

        return $categories
            ->unique(fn ($category) => Str::lower((string) $category))
            ->values();
    }

    public function extractProductAttributes(?Collection $productProductAttributes, ?array $allowedAttributeKeys = null): array
    {
        $attributes = [];
        $allowedKeysLower = is_array($allowedAttributeKeys)
            ? array_map(fn ($key) => Str::lower((string) $key), $allowedAttributeKeys)
            : null;

        $productProductAttributes?->each(function ($productProductAttribute) use (&$attributes, $allowedKeysLower) {
            $productAttribute = $productProductAttribute?->productAttribute;
            if ($productAttribute === null || $productAttribute->value === null || $productAttribute->value === '') {
                return;
            }

            if (is_array($allowedKeysLower)) {
                $productKeyLower = Str::lower((string) $productAttribute->key);
                if (! in_array($productKeyLower, $allowedKeysLower, true)) {
                    return;
                }
            }

            // Only keep the first occurrence of each attribute key
            if (array_key_exists(Str::lower($productAttribute->key), $attributes)) {
                return;
            }

            $attributes[Str::lower($productAttribute->key)] = $productAttribute->value;
        });

        return $attributes;
    }

    public function extractCategories(?Product $product, ?array $allowedAttributeKeys = null, ?array $allowedAttributeValues = null): ?Collection
    {
        if ($product?->productProductAttributes === null) {
            return collect();
        }

        $allowedKeysLower = is_array($allowedAttributeKeys)
            ? array_map(fn ($key) => Str::lower((string) $key), $allowedAttributeKeys)
            : null;
        $allowedValuesLower = is_array($allowedAttributeValues)
            ? array_map(fn ($value) => Str::lower((string) $value), $allowedAttributeValues)
            : null;

        return $product->productProductAttributes
            ->filter(function ($pivot) use ($allowedKeysLower) {
                $attr = $pivot?->productAttribute;
                if ($attr === null || $attr->value === null || $attr->value === '') {
                    return false;
                }

                if (is_array($allowedKeysLower)) {
                    $attrKeyLower = Str::lower((string) $attr->key);
                    if (! in_array($attrKeyLower, $allowedKeysLower, true)) {
                        return false;
                    }
                }

                return true;
            })
            ->flatMap(function ($pivot) {
                $attribute = $pivot->productAttribute;

                return Category::parseCategoryValues(
                    value: (string) $attribute->value,
                    key: (string) $attribute->key,
                );
            })
            ->filter(function (string $value) use ($allowedValuesLower) {
                if (! is_array($allowedValuesLower)) {
                    return true;
                }

                return in_array(Str::lower($value), $allowedValuesLower, true);
            })
            ->unique(fn ($value) => Str::lower((string) $value))
            ->values();
    }

    public function extractVariantAttributes(?Collection $variantVariantAttribute, ?array $allowedAttributeKeys = null): array
    {
        $attributes = [];
        $allowedKeysLower = is_array($allowedAttributeKeys)
            ? array_map(fn ($key) => Str::lower((string) $key), $allowedAttributeKeys)
            : null;

        $variantVariantAttribute?->each(function ($variantVariantAttributes) use (&$attributes, $allowedKeysLower) {
            $variantAttribute = $variantVariantAttributes?->variantAttribute;
            if ($variantAttribute === null || $variantAttribute->value === null || $variantAttribute->value === '') {
                return;
            }

            if (is_array($allowedKeysLower)) {
                $variantKeyLower = Str::lower((string) $variantAttribute->key);
                if (! in_array($variantKeyLower, $allowedKeysLower, true)) {
                    return;
                }
            }

            if (array_key_exists(Str::lower($variantAttribute->key), $attributes)) {
                return;
            }

            $attributes[Str::lower($variantAttribute->key)] = $variantAttribute->value;
        });

        return $attributes;
    }

    public function getUrl(object $configuration, ?string $slug): string
    {
        return ($configuration->website->product_url ?? '').'/'.$slug;
    }

    public function getImageThumbnailUrl(object $configuration, ?string $mediaName): string
    {
        return ($configuration->website->thumbnail_url ?? '').'/'.$mediaName;
    }

    public function getImages(?Collection $media, object $configuration): array
    {
        if ($media === null) {
            return [];
        }

        return $media->map(function ($media) use ($configuration) {
            return ($configuration->website->image_url ?? '').'/'.$media->name;
        })->toArray();
    }

    public function getImageFullUrl(?array $images): ?string
    {
        if (empty($images)) {
            return null;
        }

        return $images[0];
    }

    public function extractAttributesFromSlug(?string $slug): array
    {
        $attributes = [];
        if ($slug === null || $slug === '') {
            return $attributes;
        }

        if (strpos($slug, '?') !== false) {
            $parts = explode('?', $slug);
            $slug = $parts[0];

            if (isset($parts[1])) {
                parse_str($parts[1], $attributes);
            }
        }

        return $attributes;
    }

    public function variantName(?string $slug, ?string $productName, ?array $variantAttributes): ?string
    {
        $attributes = $this->extractAttributesFromSlug($slug);

        if (count($attributes) > 0) {
            foreach ($attributes as $key => $value) {
                if ($key != 'width') {
                    $productName .= ' '.$value;
                }
            }
        }

        if (count($variantAttributes) > 0) {
            foreach ($variantAttributes as $attribute) {
                $productName .= ' '.$attribute;
            }
        }

        return $productName;
    }
}
