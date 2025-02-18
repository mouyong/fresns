<?php

/*
 * Fresns (https://fresns.org)
 * Copyright (C) 2021-Present Jarvis Tang
 * Released under the Apache-2.0 License.
 */

namespace App\Fresns\Words\User;

use App\Fresns\Words\User\DTO\AddUserDTO;
use App\Fresns\Words\User\DTO\LogicalDeletionUserDTO;
use App\Fresns\Words\User\DTO\VerifyUserDTO;
use App\Helpers\ConfigHelper;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\File;
use App\Models\User as UserModel;
use App\Models\UserRole;
use App\Models\UserStat;
use App\Utilities\ConfigUtility;
use Fresns\CmdWordManager\Traits\CmdWordResponseTrait;
use Illuminate\Support\Facades\Hash;

class User
{
    use CmdWordResponseTrait;

    /**
     * @param $wordBody
     * @return array
     *
     * @throws \Throwable
     */
    public function addUser($wordBody)
    {
        $dtoWordBody = new AddUserDTO($wordBody);
        $langTag = \request()->header('langTag', ConfigHelper::fresnsConfigDefaultLangTag());

        $account = Account::where('aid', $dtoWordBody->aid)->first();
        if (empty($account)) {
            return $this->failure(
                34301,
                ConfigUtility::getCodeMessage(34301, 'Fresns', $langTag)
            );
        }

        $userArr = [
            'account_id' => $account->id,
            'username' => $dtoWordBody->username,
            'nickname' => $dtoWordBody->nickname,
            'password' => isset($dtoWordBody->password) ? Hash::make($dtoWordBody->password) : null,
            'avatarFid' => isset($dtoWordBody->avatarFid) ? File::where('fid', $dtoWordBody->avatarFid)->value('id') : null,
            'avatarUrl' => $dtoWordBody->avatar_file_url ?? null,
            'gender' => $dtoWordBody->gender ?? 0,
            'birthday' => $dtoWordBody->birthday ?? null,
            'timezone' => $dtoWordBody->timezone ?? null,
            'language' => $dtoWordBody->language ?? null,
        ];
        $userModel = UserModel::create(array_filter($userArr));

        $defaultRoleId = ConfigHelper::fresnsConfigByItemKey('default_role');
        $roleArr = [
            'user_id' => $userModel->id,
            'role_id' => $defaultRoleId,
            'is_main' => 1,
        ];
        UserRole::create($roleArr);

        $statArr = ['user_id' => $userModel->id];
        UserStat::create($statArr);

        return $this->success([
            'aid' => $account->aid,
            'uid' => $userModel->uid,
            'username' => $userModel->username,
            'nickname' => $userModel->nickname,
        ]);
    }

    /**
     * @param $wordBody
     * @return array
     *
     * @throws \Throwable
     */
    public function verifyUser($wordBody)
    {
        $dtoWordBody = new VerifyUserDTO($wordBody);
        $langTag = \request()->header('langTag', ConfigHelper::fresnsConfigDefaultLangTag());

        $user = UserModel::where('uid', $dtoWordBody->uid)->first();
        $aid = $user->account->aid;

        if (empty($user) || $dtoWordBody->aid != $aid) {
            return $this->failure(
                35201,
                ConfigUtility::getCodeMessage(35201, 'Fresns', $langTag)
            );
        }

        $loginErrorCount = ConfigUtility::getLoginErrorCount($user->account->id, $user->id);

        if ($loginErrorCount >= 5) {
            return $this->failure(
                34306,
                ConfigUtility::getCodeMessage(34306, 'Fresns', $langTag),
            );
        }

        if (! empty($user->password)) {
            if (empty($dtoWordBody->password)) {
                return $this->failure(
                    34111,
                    ConfigUtility::getCodeMessage(34111, 'Fresns', $langTag),
                );
            }

            if (! Hash::check($dtoWordBody->password, $user->password)) {
                return $this->failure(
                    35204,
                    ConfigUtility::getCodeMessage(35204, 'Fresns', $langTag),
                );
            }
        }

        $data['aid'] = $user->account->aid;
        $data['uid'] = $user->uid;

        return $this->success($data);
    }

    /**
     * @param $wordBody
     * @return array
     *
     * @throws \Throwable
     */
    public function logicalDeletionUser($wordBody)
    {
        $dtoWordBody = new LogicalDeletionUserDTO($wordBody);

        $user = UserModel::where('uid', $dtoWordBody->uid)->first();

        $user->delete();

        Conversation::where('a_user_id', $user->id)->update(['a_is_deactivate' => 0]);
        Conversation::where('b_user_id', $user->id)->update(['b_is_deactivate' => 0]);

        return $this->success();
    }
}
