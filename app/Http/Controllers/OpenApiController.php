<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class OpenApiController extends Controller
{
    public function ui()
    {
        abort_unless(app()->environment(['local','testing']),404);
        return response()->view('swagger');
    }

    public function json()
    {
        $contents=file_get_contents(base_path('docs/openapi.json'));
        abort_if($contents===false,503,'OpenAPI document is unavailable.');
        return response($contents,200,['Content-Type'=>'application/json','Cache-Control'=>'no-store']);
    }
}
