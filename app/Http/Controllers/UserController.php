<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get the authenticated User.
     *
     * @param \Illuminate\Http\Request $request
     * @return string[]
     * @response array{name: "admin"}
     */
    public function user(Request $request)
    {
        return $request->user()?->only('name');
    }
}
