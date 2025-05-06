<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function getUser(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['message' => 'Bạn chưa đăng nhập'], 401);
        }
        return response()->json($user, 200);
    }

 

    public function update(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Bạn chưa đăng nhập'], 401);
        }
        Log::info('Request data:', $request->all());
        $validator = Validator::make($request->all(), [
            'user_name'       => 'sometimes|string|max:255',
            'gmail'           => 'sometimes|email|unique:users,gmail,' . $user->id,
            'current_password'=> 'sometimes|required_with:new_password|string',
            'new_password'    => 'sometimes|required_with:current_password|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            Log::error('Validation errors:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        if ($request->filled('current_password') && $request->filled('new_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json(['message' => 'Mật khẩu hiện tại không chính xác'], 400);
            }
            $user->password = Hash::make($request->new_password);
            $user->save();
        }

        $data = $request->only('user_name', 'gmail');
        Log::info('Data to update:', $data);
        $user->update($data);
        Log::info('User after update:', $user->toArray());

        return response()->json(['message' => 'Cập nhật thành công', 'user' => $user], 200);
    }
    
    public function destroy($id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'Không tìm thấy người dùng'], 404);
        }
        $user->delete();
        return response()->json(['message' => 'Xóa người dùng thành công'], 200);
    }
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account' => 'required|string',
            'gmail' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Tìm user theo account và gmail
        $user = User::where('account', $request->account)
                    ->where('gmail', $request->gmail)
                    ->first();

        if (!$user) {
            return response()->json(['message' => 'Thông tin không chính xác'], 404);
        }

        // Tạo mật khẩu mới và lưu
        $newPassword = Str::random(8);
        $user->password = Hash::make($newPassword);
        $user->save();

        // Trả về response với mật khẩu mới
        return response()->json([
            'message' => 'Mật khẩu làm mới thành công',
            'new_password' => $newPassword
        ], 200);
    }
}
