<?php

namespace App\Http\Controllers;

use App\Models\DietPlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DietPlanController extends Controller
{
    public function index()
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
        return DietPlan::all();
    }

    public function store()
    {
        DietPlan::create([
            'user_id' => auth()->id(),
            'status' => 'requested'
        ]);
        return response()->json();
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'requested','in_progress', 'sent'
                ])],
        ]);

        DietPlan::create([
            'status' => $data['status'],
        ]);
        return response()->json();
    }

    public function show(DietPlan $dietPlan)
    {
        if (auth()->id() !== $dietPlan['user_id'] && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
        return $dietPlan;
    }

    public function destroy(DietPlan $dietPlan)
    {
        if (auth()->id() !== $dietPlan['user_id'] && auth()->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
        $dietPlan->delete();

        return response()->json(null, 204);
    }
}
