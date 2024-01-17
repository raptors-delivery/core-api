<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Events\UserRemovedFromCompany;
use Fleetbase\Exports\UserExport;
use Fleetbase\Http\Controllers\FleetbaseController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\Internal\AcceptCompanyInvite;
use Fleetbase\Http\Requests\Internal\InviteUserRequest;
use Fleetbase\Http\Requests\Internal\ResendUserInvite;
use Fleetbase\Http\Requests\Internal\UpdatePasswordRequest;
use Fleetbase\Http\Requests\Internal\ValidatePasswordRequest;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Notifications\UserAcceptedCompanyInvite;
use Fleetbase\Notifications\UserInvited;
use Fleetbase\Support\NotificationRegistry;
use Fleetbase\Support\TwoFactorAuth;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class UserController extends FleetbaseController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'user';

    /**
     * Responds with the currently authenticated user.
     *
     * @return \Illuminate\Http\Response
     */
    public function current(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->error('No user session found', 401);
        }

        return response()->json(
            [
                'user' => $user,
            ]
        );
    }

    /**
     * Get the current user's two factor authentication settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTwoFactorSettings(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->error('No user session found', 401);
        }

        $twoFaSettings = TwoFactorAuth::getTwoFaSettingsForUser($user);

        return response()->json($twoFaSettings->value);
    }

    /**
     * Save the current user's two factor authentication settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function saveTwoFactorSettings(Request $request)
    {
        $twoFaSettings = $request->array('twoFaSettings');
        $user          = $request->user();

        if (!$user) {
            return response()->error('No user session found', 401);
        }

        $twoFaSettings = TwoFactorAuth::saveTwoFaSettingsForUser($user, $twoFaSettings);

        return response()->json($twoFaSettings->value);
    }

    /**
     * Creates a user, adds the user to company and sends an email to user about being added.
     *
     * @return \Illuminate\Http\Response
     */
    public function inviteUser(InviteUserRequest $request)
    {
        // $data = $request->input(['name', 'email', 'phone', 'status', 'country', 'date_of_birth']);
        $data  = $request->input('user');
        $email = strtolower($data['email']);

        // set company
        $data['company_uuid'] = session('company');
        $data['status']       = 'pending'; // pending acceptance
        $data['type']         = 'user'; // set type as regular user
        $data['created_at']   = Carbon::now(); // jic

        // make sure user isn't already invited
        $isAlreadyInvited = Invite::where([
            'company_uuid' => session('company'),
            'subject_uuid' => session('company'),
            'protocol'     => 'email',
            'reason'       => 'join_company',
        ])->whereJsonContains('recipients', $email)->exists();

        if ($isAlreadyInvited) {
            return response()->error('This user has already been invited to join this organization.');
        }

        // get the company inviting
        $company = Company::where('uuid', session('company'))->first();

        // check if user exists already
        $user = User::where('email', $email)->first();

        // if new user, create user
        if (!$user) {
            $user = User::create($data);
        }

        // create invitation
        $invitation = Invite::create([
            'company_uuid'    => session('company'),
            'created_by_uuid' => session('user'),
            'subject_uuid'    => $company->uuid,
            'subject_type'    => Utils::getMutationType($company),
            'protocol'        => 'email',
            'recipients'      => [$user->email],
            'reason'          => 'join_company',
        ]);

        // notify user
        $user->notify(new UserInvited($invitation));

        return response()->json(['user' => $user]);
    }

    /**
     * Resend invitation to pending user.
     *
     * @return \Illuminate\Http\Response
     */
    public function resendInvitation(ResendUserInvite $request)
    {
        $user    = User::where('uuid', $request->input('user'))->first();
        $company = Company::where('uuid', session('company'))->first();

        // create invitation
        $invitation = Invite::create([
            'company_uuid'    => session('company'),
            'created_by_uuid' => session('user'),
            'subject_uuid'    => $company->uuid,
            'subject_type'    => Utils::getMutationType($company),
            'protocol'        => 'email',
            'recipients'      => [$user->email],
            'reason'          => 'join_company',
        ]);

        // notify user
        $user->notify(new UserInvited($invitation));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Accept invitation to join a company/organization.
     *
     * @return \Illuminate\Http\Response
     */
    public function acceptCompanyInvite(AcceptCompanyInvite $request)
    {
        $invite = Invite::where('code', $request->input('code'))->with(['subject'])->first();

        // get invited email
        $email = Arr::first($invite->recipients);

        if (!$email) {
            return response()->error('Unable to locate the user for this invitation.');
        }

        // get user from invite
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->error('Unable to locate the user for this invitation.');
        }

        // get the company who sent the invite
        $company = $invite->subject;

        if (!$company) {
            return response()->error('The organization that invited you no longer exists.');
        }

        // determine if user needs to set password (when status pending)
        $isPending = $needsPassword = $user->status === 'pending';

        // add user to company
        CompanyUser::create([
            'user_uuid'    => $user->uuid,
            'company_uuid' => $company->uuid,
        ]);

        // activate user
        if ($isPending) {
            $user->update(['status' => 'active', 'email_verified_at' => Carbon::now()]);
        }

        // create authentication token for user
        $token = $user->createToken($invite->code);

        // Notify company that user has accepted their invite
        NotificationRegistry::notify(UserAcceptedCompanyInvite::class, $company, $user);

        return response()->json([
            'status'         => 'ok',
            'token'          => $token->plainTextToken,
            'needs_password' => $needsPassword,
        ]);
    }

    /**
     * Deactivates a user.
     *
     * @return \Illuminate\Http\Response
     */
    public function deactivate($id)
    {
        if (!$id) {
            return response()->error('No user to deactivate', 401);
        }

        $user = User::where('uuid', $id)->first();

        if (!$user) {
            return response()->error('No user found', 401);
        }

        // $user->deactivate();
        // deactivate for company session
        $user->companies()->where('company_uuid', session('company'))->update(['status' => 'inactive']);
        $user = $user->refresh();

        return response()->json([
            'message' => 'User deactivated',
            'status'  => $user->session_status,
        ]);
    }

    /**
     * Activates/re-activates a user.
     *
     * @return \Illuminate\Http\Response
     */
    public function activate($id)
    {
        if (!$id) {
            return response()->error('No user to activate', 401);
        }

        $user = User::where('uuid', $id)->first();

        if (!$user) {
            return response()->error('No user found', 401);
        }

        // $user->activate();
        // activate for company session
        // maybe we dont want to activate for all organizations
        $user->companies()->where('company_uuid', session('company'))->update(['status' => 'active']);
        $user = $user->refresh();

        return response()->json([
            'message' => 'User activated',
            'status'  => $user->session_status,
        ]);
    }

    /**
     * Removes this user from the current company.
     *
     * @return \Illuminate\Http\Response
     */
    public function removeFromCompany($id)
    {
        if (!$id) {
            return response()->error('No user to remove', 401);
        }

        // get user to remove from company
        $user = User::where('uuid', $id)->first();

        if (!$user) {
            return response()->error('No user found', 401);
        }

        // get the current company user is being removed from
        $company = Company::where('uuid', session('company'))->first();

        if (!$company) {
            return response()->error('Unable to remove user from this company', 401);
        }

        /** @var \Illuminate\Support\Collection */
        $userCompanies = $user->companies()->get();

        // only a member to one company then delete the user
        if ($userCompanies->count() === 1) {
            $user->delete();
        } else {
            $user->companies()->where('company_uuid', $company->uuid)->delete();

            // trigger event user removed from company
            event(new UserRemovedFromCompany($user, $company));

            // set to other company for next login
            $nextCompany = $userCompanies->filter(function ($userCompany) {
                return $userCompany->company_uuid !== session('company');
            })->first();

            if ($nextCompany) {
                $user->update(['company_uuid' => $nextCompany->uuid]);
            } else {
                $user->delete();
            }
        }

        return response()->json([
            'message' => 'User removed',
        ]);
    }

    /**
     * Updates the current users password.
     *
     * @return \Illuminate\Http\Response
     */
    public function setCurrentUserPassword(UpdatePasswordRequest $request)
    {
        $password = $request->input('password');

        $user = $request->user();

        if (!$user) {
            return response()->error('User not authenticated');
        }

        $user->changePassword($password);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Endpoint to quickly search/query.
     *
     * @return \Illuminate\Http\Response
     */
    public function searchRecords(Request $request)
    {
        $query   = $request->input('query');
        $results = User::select(['uuid', 'name'])
            ->search($query)
            ->limit(12)
            ->get();

        return response()->json($results);
    }

    /**
     * Export the users to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format   = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('users-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new UserExport(), $fileName);
    }

    /**
     * Get user and always return with driver.
     *
     * @return \Illuminate\Http\Response
     */
    public static function getWithDriver($id, Request $request)
    {
        $user           = User::select(['public_id', 'uuid', 'email', 'name', 'phone', 'type'])->where('uuid', $id)->with(['driver'])->first();
        $json           = $user->toArray();
        $json['driver'] = $user->driver;

        return response()->json(['user' => $user]);
    }

    /**
     * Validate the user's current password.
     *
     * @param \Fleetbase\Http\Requests\Internal\ValidatePasswordRequest $request
     * @return \Illuminate\Http\Response
     */
    public function validatePassword(ValidatePasswordRequest $request)
    {
        $user = $request->user();
        $currentPassword = $request->input('current_password');
        $confirmPassword = $request->input('confirm_password');

        if (!$user || !$user->checkPassword($currentPassword)) {
            return response()->error('Invalid current password', 422);
        }

        if ($currentPassword !== $confirmPassword) {
            return response()->error('Password is not matching');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Change the user's password.
     *
     * @param \Fleetbase\Http\Requests\Internal\UpdatePasswordRequest $request
     * @return \Illuminate\Http\Response
     */
    public function changeUserPassword(UpdatePasswordRequest $request)
    {
        $user = $request->user();
        $newPassword = $request->input('password');
        $newConfirmPassword = $request->input('password_confirmation');

        if ($newPassword !== $newConfirmPassword) {
            return response()->error('Password is not matching');
        }

        $user->changePassword($newPassword);

        return response()->json(['status' => 'ok']);
    }
}
