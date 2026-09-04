<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;

class CategoryController extends Controller
{
    /**
     * Full category tree: 11 top-level categories -> subcategories.
     */
    public function tree()
    {
        $roots = Category::roots()
            ->with('children')
            ->get();

        return CategoryResource::collection($roots);
    }
}
