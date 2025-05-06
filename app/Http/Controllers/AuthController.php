<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try{
        $request->validate([
            'user_name' => 'required|string|max:255',
            'account' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'gmail' => 'required|string|email|max:255|unique:users,gmail',
        ]);

        $user = User::create([
            'user_name' => $request->user_name,
            'account' => $request->account,
            'password' => Hash::make($request->password),
            'gmail' => $request->gmail,
        ]);

        return response()->json([
            'message' => 'Đăng ký thành công',
            'user' => $user
        ], 201);
        }
        catch(Exception $e)
        {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra, vui lòng thử lại sau!',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('account', $request->login)->orWhere('gmail', $request->login)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Tài khoản hoặc mật khẩu không đúng !!!',
            ], 401);
        }

        $token = $user->createToken('MusicAppToken')->plainTextToken;

        return response()->json(['message' => 'Đăng nhập thành công', 'token' => $token], 200);
    }
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Đăng xuất thành công'], 200);
    }
}
