<?php

namespace Vipbressanon\LiveTool\Controllers;

use Illuminate\Routing\Controller;
use View;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Redis;
use Vipbressanon\LiveTool\Servers\CourseServer;
use Vipbressanon\LiveTool\Servers\RoomServer;
use Vipbressanon\LiveTool\Servers\UsersServer;
use Vipbressanon\LiveTool\Servers\RecordServer;
use Vipbressanon\LiveTool\Servers\BalanceServer;
use Log;
use Session;

class LiveController extends Controller
{

    // 进入直播间
    public function getRoom(Request $request, $hash_id = '')
    {
        $auth = config('livetool.auth');
        $users = Auth::guard($auth)->user();
        
        if (!$users) {
            //Auth::guard($auth)->loginUsingId($request->input('uid'));
            //$users = Auth::guard($auth)->user();
            $url = config('livetool.loginurl');
            return redirect($url.'/'.$hash_id);
        }
        $cs = new CourseServer();
        $course = $cs->detail($hash_id);
        if ($course) {
            $platform = $this->platform();
            $rs = new RoomServer();
            // 判断用户是否为讲师
            $isteacher = $users->id == $course['teacher_id'] ? 1 : 0;
            
            $us = new UsersServer();
            // 获取用户令牌
            $info = $us->sig($users->hash_id, $users->id, $course['team_id']);
            // 获取房间信息
            $room = $rs->detail($course['id'], $course['teacher_id'],$info['hash_id'],$isteacher);
            // 获取房间用户信息
            $us->detail($course['id'], $room['id'], $users->id, $platform, $course['team_id']);
            
            
            // 用户黑名单,被讲师踢出的将不能再次进入
            $black = $rs->black($room['id'], $info['id']);
            // 获取课程分享信息
            $share = $cs->share($course['id']);
            // 判断用户是否在白名单内
            $iswhite = $cs->iswhite($course['id'], $users->id);
            // 判断课程所属团队余额是否大于等于0
            $balance = $cs->balance($course['team_id']);
            // 判断是否有权限进入
            $role = $this->role($course, $black, $iswhite, $balance,$room['online_num']);
            if ($role[0] == 203) {
                $url = config('livetool.loginurl');
                return redirect($url.'/'.$hash_id);
            }
            // 根据用户身份进入不同页面
            $viewtype = 'livetool::small';
            if ($course['type'] == 1) {
                $viewtype = 'livetool::small';
            } elseif ($course['type'] == 2) {
                $viewtype = 'livetool::large';
            } elseif ($course['type'] == 3) {
                $viewtype = 'livetool::public';
            }
            $view = $isteacher ? $viewtype.'.teacher' : $viewtype.'.student';
            return view($view)
                    ->with('platform', $platform)
                    ->with('course', $course)
                    ->with('room', $room)
                    ->with('info', $info)
                    ->with('share', $share)
                    ->with('isteacher', $isteacher)
                    ->with('role', $role);
        } else {
            abort(404);
        }
    }
    
    private function platform()
    {
        $num = 0;
        $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        $is_pc = (strpos($agent, 'windows nt')) ? true : false;
        $is_mobile = (strpos($agent, 'iphone') || strpos($agent, 'android') || strpos($agent, 'ios')) ? true : false;
        $is_ipad = (strpos($agent, 'ipad') || strpos($agent, 'ipod')) ? true : false;
        if ($is_mobile) {
            $num = 1;
        } else if ($is_ipad) {
            $num = 2;
        } else {
            $num = 0;
        }
        return $num;
    }
    
    // 记录报错信息
    public function postErrors(Request $request)
    {
		$status = config('livetool.error_status');
		if ($status) {
			$auth = config('livetool.auth');
			$users = Auth::guard($auth)->user();
			$cs = new CourseServer();
			$cs->errors($request->all());
		}
        return response()->json(['error'=>'']);
    }
    
    // 上课
    public function postRoomStart(Request $request)
    {
        $course_id = $request->input('course_id');
        $room_id = $request->input('room_id');
        $rs = new RoomServer();
        $starttime = $rs->start($course_id, $room_id);
        return response()->json(['error'=>'', 'starttime' => $starttime]);
    }
    
    // 下课
    public function postRoomEnd(Request $request)
    {
        $course_id = $request->input('course_id');
        $room_id = $request->input('room_id');
        $rs = new RoomServer();
        $rs->end($course_id, $room_id);
        return response()->json(['error'=>'']);
    }
    
    // 切换直播模式
    public function postRoomType(Request $request)
    {
        $room_id = $request->input('room_id');
        $roomtype = $request->input('roomtype');
        $rs = new RoomServer();
        $rs->type($room_id, $roomtype);
        return response()->json(['error'=>'']);
    }
    
    // 切换聊天讨论
    public function postRoomChat(Request $request)
    {
        $room_id = $request->input('room_id');
        $roomchat = $request->input('roomchat');
        $rs = new RoomServer();
        $rs->chat($room_id, $roomchat);
        return response()->json(['error'=>'']);
    }
    
    // 切换全员上台
    public function postRoomSpeak(Request $request)
    {
        $room_id = $request->input('room_id');
        $roomspeak = $request->input('roomspeak');
        $rs = new RoomServer();
        $rs->speak($room_id, $roomspeak);
        return response()->json(['error'=>'']);
    }
    
    // 切换是否可举手
    public function postRoomHand(Request $request)
    {
        $room_id = $request->input('room_id');
        $roomhand = $request->input('roomhand');
        $rs = new RoomServer();
        $rs->hand($room_id, $roomhand);
        return response()->json(['error'=>'']);
    }
    
    // 踢出课堂
    public function postRoomKick(Request $request)
    {
        $course_id = $request->input('course_id');
        $room_id = $request->input('room_id');
        $hash_id = $request->input('hash_id');
        $us = new UsersServer();
        $us->kick($course_id, $room_id, $hash_id);
        return response()->json(['error'=>'']);
    }
    
    // 点赞，上台，举手记录次数
    public function postOperate(Request $request)
    {
        $room_id = $request->input('room_id');
        $hash_id = $request->input('hash_id');
        $type = $request->input('type');
        $us = new UsersServer();
        $res = $us->operate($room_id, $hash_id, $type);
        return response()->json(['error'=>'']);
    }
    
    // 在线人员信息
    public function postOnline(Request $request)
    {
        $add = $request->input('add', []);
        $room_id = $request->input('room_id');
        $us = new UsersServer();
        $info = $us->info($add, $room_id);
        return response()->json(['error'=>'', 'info'=>$info]);
    }

    // 设备选择，检测
    public function getCheck(Request $request)
    {
        $type = $request->input('type', 1);
        $tea = $request->input('tea', 0);
        return view('livetool::check')
                ->with('type', $type)
                ->with('tea', $tea);
    }
    
    // 录制开始
    public function postRecord(Request $request)
    {
        Log::info("录制事件 request",array('re' => $request->all()));
        $room_id = $request->input('room_id');
        $status = $request->input('status');
        $rs = new RecordServer();
        $res = $rs->hander($room_id, $status);
        return response()->json(['error'=>'']);
    }

    // 录制回调
    public function postRecordCallBack(Request $request)
    {
        
        $rs = new RecordServer();
        $res = $rs->balance($request->all());
        return response()->json(['error'=>'']);
    }
    
    public function getBrowser(Request $request)
    {
        return view('livetool::browser');
    }

    public function getSpeedTest() {
        return view('livetool::speedtest');
    }
    
    
    private function role($course, $black, $iswhite, $balance,$online_num)
    {
        $data = [201, '无法进入直播间'];
    	if (!$iswhite && $course['invite_type'] == 0) {
    		return [201, '无法进入直播间'];
    	}
    	if (!$iswhite && $course['invite_type'] == 1) {
    		return [203, '请输入口令'];
    	}		
        if(!$iswhite && $course['invite_type'] == 2){
            return [ 201 ,'不再白名单内，无法进入'];
        }   
        if($online_num >= $course['up_top']+$course['down_top']){
            return [ 201 ,'房间人数已满，无法进入'];
        }
        if ($course['status'] == 0) {
            if ($balance) {
                $data = [202, '请等待讲师开课'];
            } else {
                $data = [204, '讲师账户余额不足'];
            }
        } elseif ($course['status'] == 1) {
            if (!$balance) {
                $data = [204, '讲师账户余额不足'];
            } elseif ($black) {
                $data = [201, '被讲师踢出'];
            } else {
                $data = [200, '正常进入'];
            }
        } elseif ($course['status'] == 2) {
            $data = [201, '已下课'];
        }
        return $data;
    }
}