<?php

namespace App\Http\Controllers\NutritionConsultation;

use App\Http\Controllers\Controller;
use App\Models\ConsultationType;

class TypeController extends Controller
{
    public function index()
    {
        return response()->json(ConsultationType::all());
    }
}
