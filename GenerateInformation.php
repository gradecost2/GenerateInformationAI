<?php

namespace Gradecost;

use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateInformation
{
    const TIMEOUT = 10;

    private Provider $provider;
    private string $model;

    // Class constructor
    public function __construct(Provider $provider, string $model)
    {
        $this->provider = $provider;
        $this->model = $model;
    }

    // Generating product names for the specified category.
    // It is better to pass not only the category name but also the category hierarchy.
    // For example: "Home Appliances / Vacuum Cleaners / Robot Vacuum Cleaner"
    // In $languages, pass languages in the format ['uk' => 'Ukrainian', ...].
    // The first language will be the primary one, and the others will be used for translation.
    public function generateProductTitle(string $categoryNavigationPath, array $languages, string $example = null): array
    {
        // Required fields
        $requiredFields = array_map(function($key) {
            return 'title_'.$key;
        }, array_keys($languages));

        // Schema properties
        $i = 0; $properties = [];
        foreach ($languages as $key => $language) {
            if ($i > 0) $properties[] = new StringSchema('title_'.$key, 'Translate product name in language '.$language);
            else $properties[] = new StringSchema('title_'.$key, 'Product name in language '.$language);
            $i++;
        }

        // Schema
        $schema = new ObjectSchema(
            name: 'product_title',
            description: 'A structured product name',
            requiredFields: $requiredFields,
            properties: $properties,
        );

        // Example
        if (!empty($example)) $example = ' (Example '.$example.')';

        // Product name generation
        $response = Prism::structured()
            ->using($this->provider, $this->model)
            ->withSchema($schema)
            ->withPrompt('Product name for the category "'.$categoryNavigationPath.'"'.$example)
            ->usingTemperature(0.8)
            ->withClientRetry(5, 100)
            ->asStructured();

        return $response->structured;
    }

    // Generating description for the specified product.
    // In $languages, pass languages in the format ['uk' => 'Ukrainian', ...].
    // The first language will be the primary one, and the others will be used for translation.
    public function generateProductDescription(string $productTitle, array $languages): array
    {
        // Mandatory fields
        $requiredFields = array_map(function($key) {
            return 'description_'.$key;
        }, array_keys($languages));

        // Schema attributes
        $i = 0; $properties = [];
        foreach ($languages as $key => $language) {
            if ($i > 0) $properties[] = new StringSchema('description_'.$key, 'Translate product description in language '.$language);
            else $properties[] = new StringSchema('description_'.$key, 'Product description in language '.$language);
            $i++;
        }

        // Schema
        $schema = new ObjectSchema(
            name: 'product_description',
            description: 'A structured product description',
            requiredFields: $requiredFields,
            properties: $properties,
        );

        // Product description generation
        $response = Prism::structured()
            ->using($this->provider, $this->model)
            ->withSchema($schema)
            ->withPrompt('Product description for the product "'.$productTitle.'"')
            ->usingTemperature(0.8)
            ->withClientRetry(5, 100)
            ->asStructured();

        return $response->structured;
    }

    // Generating a review for the specified product.
    // In $languages, pass languages in the format ['uk' => 'Ukrainian', ...].
    public function generateProductReview(string $productTitle, string $example = null): array
    {
        // Required fields
        $requiredFields = ['text', 'rating'];

        // Schema
        $schema = new ObjectSchema(
            name: 'product_review',
            description: 'A structured product review',
            requiredFields: $requiredFields,
            properties: [
                new StringSchema('text', 'Review text'),
                new StringSchema('rating', 'Rating (1-5)'),
            ],
        );

        // Example
        if (!empty($example)) $example = ' (Example '.$example.')';

        // Product review generation
        $response = Prism::structured()
            ->using($this->provider, $this->model)
            ->withSchema($schema)
            ->withPrompt('Product review for the product "'.$productTitle.'"'.$example)
            ->usingTemperature(0.7)
            ->withClientRetry(5, 100)
            ->asStructured();

        return $response->structured;
    }

    // Searching and downloading product image by name. Configuration: https://www.pexels.com/api/documentation/
    public function findImage(string $title, string $folder, string $disk = null): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => env('PIXELS_API_KEY'),
        ])->get('https://api.pexels.com/v1/search', [
            'query' => $title,
            'orientation' => 'square',
            'size' => 'small',
        ]);

        if ($response->successful()) {
            $results = $response->json()['photos'];

            // It's not always possible to download the image by the URL, so we try to download the next one in the search results
            if (!empty($results)) {
                foreach ($results as $result) {
                    try {
                        $url = $result['src']['original'];
                        $response = Http::timeout(self::TIMEOUT)->get($url);
                    } catch (\Illuminate\Http\Client\RequestException $e) {
                        $response = null;
                    }

                    // Uploading the image to a folder
                    if ($response && $response->successful()) {
                        $extension = pathinfo(parse_url($url)['path'], PATHINFO_EXTENSION);
                        $image = $folder.'/'.Str::random(26).'.'.$extension;
                        Storage::disk(empty($disk) ? config('filesystems.default') : $disk)->put($image, $response->body());
                        return $image;
                    }
                }
            }
        }

        return null;
    }
}