<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Mail\ConfirmEmail;
use Auth, DB, Cache, Mail, Redis;
use App\{
  EmailVerification, 
  Notification, 
  Profile, 
  User,
  UserFilter
};

class AccountController extends Controller
{
    protected $filters = [
      'user.mute',
      'user.block'
    ];

    public function __construct()
    {
      $this->middleware('auth');
    }

    public function notifications(Request $request)
    {
      $this->validate($request, [
          'page' => 'nullable|min:1|max:3',
          'a'    => 'nullable|alpha_dash',
      ]);
      $profile = Auth::user()->profile;
      $action = $request->input('a');
      $timeago = Carbon::now()->subMonths(6);
      if($action && in_array($action, ['comment', 'follow', 'mention'])) {
        $notifications = Notification::whereProfileId($profile->id)
            ->whereAction($action)
            ->whereDate('created_at', '>', $timeago)
            ->orderBy('id','desc')
            ->simplePaginate(30);
      } else {
        $notifications = Notification::whereProfileId($profile->id)
            ->whereDate('created_at', '>', $timeago)
            ->orderBy('id','desc')
            ->simplePaginate(30);
      }

      return view('account.activity', compact('profile', 'notifications'));
    }

    public function followingActivity(Request $request)
    {
      $this->validate($request, [
          'page' => 'nullable|min:1|max:3',
          'a'    => 'nullable|alpha_dash',
      ]);
      $profile = Auth::user()->profile;
      $action = $request->input('a');
      $timeago = Carbon::now()->subMonths(1);
      $following = $profile->following->pluck('id');
      $notifications = Notification::whereIn('actor_id', $following)
          ->where('profile_id', '!=', $profile->id)
          ->whereDate('created_at', '>', $timeago)
          ->orderBy('notifications.id','desc')
          ->simplePaginate(30);

      return view('account.following', compact('profile', 'notifications'));
    }

    public function verifyEmail(Request $request)
    {
      return view('account.verify_email');
    }

    public function sendVerifyEmail(Request $request)
    {
        $timeLimit = Carbon::now()->subDays(1)->toDateTimeString();
        $recentAttempt = EmailVerification::whereUserId(Auth::id())
          ->where('created_at', '>', $timeLimit)->count();
        $exists = EmailVerification::whereUserId(Auth::id())->count();

        if($recentAttempt == 1 && $exists == 1) {
            return redirect()->back()->with('error', 'A verification email has already been sent recently. Please check your email, or try again later.');
        } elseif ($recentAttempt == 0 && $exists !== 0) {
            // Delete old verification and send new one.
            EmailVerification::whereUserId(Auth::id())->delete();
        }


        $user = User::whereNull('email_verified_at')->find(Auth::id());
        $utoken = hash('sha512', $user->id);
        $rtoken = str_random(40);

        $verify = new EmailVerification;
        $verify->user_id = $user->id;
        $verify->email = $user->email;
        $verify->user_token = $utoken;
        $verify->random_token = $rtoken;
        $verify->save();

        Mail::to($user->email)->send(new ConfirmEmail($verify));

        return redirect()->back()->with('status', 'Verification email sent!');
    }

    public function confirmVerifyEmail(Request $request, $userToken, $randomToken)
    {
        $verify = EmailVerification::where('user_token', $userToken)
          ->where('random_token', $randomToken)
          ->firstOrFail();

        if(Auth::id() === $verify->user_id) {
          $user = User::find(Auth::id());
          $user->email_verified_at = Carbon::now();
          $user->save();
          return redirect('/');
        }
    }

    public function fetchNotifications($id)
    {
      $key = config('cache.prefix') . ":user.{$id}.notifications";
      $redis = Redis::connection();
      $notifications = $redis->lrange($key, 0, 30);
      if(empty($notifications)) {
        $notifications = Notification::whereProfileId($id)
          ->orderBy('id','desc')->take(30)->get();
      } else {
        $notifications = $this->hydrateNotifications($notifications);
      }

      return $notifications;
    }

    public function hydrateNotifications($keys)
    {
      $prefix = 'notification.';
      $notifications = collect([]);
      foreach($keys as $key) {
        $notifications->push(Cache::get("{$prefix}{$key}"));
      }
      return $notifications;
    }

    public function messages()
    {
      return view('account.messages');
    }


    public function showMessage(Request $request, $id)
    {
      return view('account.message');
    }

    public function mute(Request $request)
    {
        $this->validate($request, [
          'type' => 'required|string',
          'item' => 'required|integer|min:1'
        ]);

        $user = Auth::user()->profile;
        $type = $request->input('type');
        $item = $request->input('item');
        $action = "{$type}.mute";

        if(!in_array($action, $this->filters)) {
          return abort(406);
        }
        $filterable = [];
        switch ($type) {
          case 'user':
            $profile = Profile::findOrFail($item);
            if($profile->id == $user->id) {
              return abort(403);
            }
            $class = get_class($profile);
            $filterable['id'] = $profile->id;
            $filterable['type'] = $class;
            break;
          
          default:
            # code...
            break;
        }

        $filter = UserFilter::firstOrCreate([
          'user_id' => $user->id,
          'filterable_id' => $filterable['id'],
          'filterable_type' => $filterable['type'],
          'filter_type' => 'mute'
        ]);

        return redirect()->back();

    }

    public function block(Request $request)
    {
        $this->validate($request, [
          'type' => 'required|string',
          'item' => 'required|integer|min:1'
        ]);
        
        $user = Auth::user()->profile;
        $type = $request->input('type');
        $item = $request->input('item');
        $action = "{$type}.block";
        if(!in_array($action, $this->filters)) {
          return abort(406);
        }
        $filterable = [];
        switch ($type) {
          case 'user':
            $profile = Profile::findOrFail($item);
            $class = get_class($profile);
            $filterable['id'] = $profile->id;
            $filterable['type'] = $class;
            break;
          
          default:
            # code...
            break;
        }

        $filter = UserFilter::firstOrCreate([
          'user_id' => $user->id,
          'filterable_id' => $filterable['id'],
          'filterable_type' => $filterable['type'],
          'filter_type' => 'block'
        ]);

        return redirect()->back();

    }

}
