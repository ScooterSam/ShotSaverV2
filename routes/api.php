<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth.secure-key')->group(function () {
	Route::post('/processing/finish-up', 'FileProcessing@finish');
	Route::post('/processing/callback', 'FileProcessing@callback');
});
Route::middleware('auth:api')->group(function () {

    Route::post('/upload', 'FileController@upload');
    Route::get('/files', 'FileController@listFiles');
    Route::get('/favourites', 'FileController@listFavourites');

    Route::get('/files/{file}/download/ld', 'FileController@downloadLowDef');
    Route::post('/files/{file}/favourite', 'FileController@favouriteToggle');
    Route::post('/files/{file}/delete', 'FileController@delete');

});
