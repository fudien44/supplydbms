<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use phpseclib3\Crypt\DES;
use App\Models\User;

class loginController extends Controller
{
  public function login(Request $request)
{
    $data = $request->validate([
        'username' => ['required', 'string'],
        'password' => ['required', 'string'],
    ]);

    $encryptedPassword = $this->encryptLegacyDes($data['password']);

    $user = User::where('Username', $data['username'])
        ->where('Password', $encryptedPassword)
        ->first();

    if (!$user) {
        throw ValidationException::withMessages([
            'username' => 'Invalid username or password.',
        ]);
    }

    if (!$user->isactive) {
        throw ValidationException::withMessages([
            'username' => 'Your account is inactive.',
        ]);
    }

    $token = $user->createToken('supplydbms-token')->plainTextToken;

    return response()->json([
        'token' => $token,
        'token_type' => 'Bearer',
        'user' => [
            'id' => $user->UserId,
            'username' => $user->Username,
            'email' => $user->Email,
            'role' => $user->roleid ?? null,
            'employeeId' => $user->empid ?? null,
            'isActive' => $user->isactive ?? null,
        ]
    ]);
}

    private function encryptLegacyDes(string $plainText): string
    {
        $encryptKey = "2013;[pnuLIT)WebCodeExpert";
        $key = substr($encryptKey, 0, 8);
        $iv = chr(18) . chr(52) . chr(86) . chr(120) . chr(144) . chr(171) . chr(205) . chr(239);

        $des = new DES('cbc');
        $des->setKey($key);
        $des->setIV($iv);
        $des->enablePadding();

        return base64_encode($des->encrypt($plainText));
    }
}