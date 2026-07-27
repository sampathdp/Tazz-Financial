<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedNumber;

class GeneratedNumberController extends Controller
{
    public function index()
    {
        return GeneratedNumber::with(['branch:id,name,code', 'loanType:id,name,code', 'generator:id,name'])
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }
}
