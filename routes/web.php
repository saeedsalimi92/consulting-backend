<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => response()->json(['app'=>'Elham Consulting API','status'=>'ok']));
