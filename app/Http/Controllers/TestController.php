<?php

namespace App\Http\Controllers;

use Google\Service\Drive;
use Illuminate\Http\Request;
use Google\Http\MediaFileUpload;

class TestController extends Controller
{
    public function showForm()
    {
        return view('welcome');
    }
}
