<?php

namespace App\Http\Controllers\Integral;


use App\Http\Controllers\Controller;
use App\Models\Role;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Dict;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\Departments;
use App\Models\Menu;

class DingController extends Controller
{
    public $seeIds;
    public $office;
    public $projectsCache;
    public $projectPlanCache;
    public function getSeeIds($userId)
    {
        if ($userId) {
            $userInfo = User::where('ding_user_id',$userId)->first();
            if($userInfo){
                $roleId = $userInfo['group_id'];
                $this->office = $userInfo['office'];
                $dataType = Role::where('id', $roleId)->first()->data_type;

                if ($dataType === 0) {
                    $userIds = User::all()->toArray();
                    $this->seeIds = array_column($userIds, 'id');
                }
                if ($dataType === 1) {
                    $departmentIds = DB::table('iba_role_department')->where('role_id', $roleId)->get()->toArray();
                    $departmentIds = array_column($departmentIds, 'department_id');
                    $userIds = User::whereIn('department_id', $departmentIds)->get()->toArray();
                    $this->seeIds = array_column($userIds, 'id');
                }
                if ($dataType === 2) { 
                    $this->seeIds = [$userInfo['id']];
                }
            }
        }
    }
    // curl
    public function postCurl($url,$data,$type) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        if($type=='post'){
            curl_setopt($ch, CURLOPT_POST, TRUE);
            //设置post数据
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        $json =  curl_exec($ch);
        curl_close($ch);
        return $json;
    }
    //获取钉钉token
    public function getToken(){
        $appKey=env("Ding_App_Key");
        $appSecret=env("Ding_App_Secret");
        $url='https://oapi.dingtalk.com/gettoken?appkey='.$appKey.'&appsecret='.$appSecret;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_URL, $url);
        $json =  curl_exec($ch);
        curl_close($ch);
        $arr=json_decode($json,true);
        Cache::put('dingAccessToken', $arr['access_token'], 7200);
        // dd($arr['access_token']);
        dd($arr);
    }
    //获取钉钉用户信息
    public function userId(Request $request){
        $data = $request->all();
        $appKey=env("Ding_App_Key");
        $appSecret=env("Ding_App_Secret");
        $accessToken=Cache::get('dingAccessToken');
        if(!$accessToken){
            $this->getToken();
            $accessToken=Cache::get('dingAccessToken');
        }
        $user_id_url='https://oapi.dingtalk.com/user/getuserinfo?access_token='.$accessToken.'&code='.$data['code'];
        $user_ids=$this->postCurl($user_id_url,[],'get');
        $user_id=json_decode($user_ids,true);
        $url='https://oapi.dingtalk.com/user/get?access_token='.$accessToken.'&userid='.$user_id['userid'];
        $json=$this->postCurl($url,[],'get');
        $arr=json_decode($json,true);
        Cache::put('userid', $arr['userid'], 7200);
        $ids = DB::table('users')->where('phone', $arr['mobile'])->value('id');
        if($ids){
            $result = DB::table('users')->where('phone', $arr['mobile'])->update(['ding_user_id'=>$arr['userid']]);
        }
        return response()->json(['result' => $arr,'ids'=>$ids?$ids:false], 200);;
    }
    //销售数据列表
    public function salesDataList(Request $request)
    {   
        $params =  $request->input();

        $data = DB::table('integral');
        if (isset($params['pageNumber']) && isset($params['pageSize'])) {
            $data = $data
                ->limit($params['pageSize'])
                ->offset(($params['pageNumber'] - 1) * $params['pageSize']);
        }
        $data=$data->get()->toArray();
        $count = DB::table('integral')->count();
        $project_type_v = Dict::getOptionsArrByName('产品类型价值');
        $project_type_d = Dict::getOptionsArrByName('产品类型发展');
        $business_type = Dict::getOptionsArrByName('业务类型');
        $is_new_user = Dict::getOptionsArrByName('是否新用户');
        $terminal_type = Dict::getOptionsArrByName('终端类型');
        $set_meal = Dict::getOptionsArrByName('套餐');
        $set_meal_0 = Dict::getOptionsArrByName('融合套餐');
        $set_meal_1 = Dict::getOptionsArrByName('单卡套餐');
        $set_meal_2 = Dict::getOptionsArrByName('智慧企业套餐');
        $set_up_meal = Dict::getOptionsArrByName('升级套餐');
        $set_up_meal_0 = Dict::getOptionsArrByName('智慧家庭升级包');
        $set_up_meal_1 = Dict::getOptionsArrByName('5G升级包');
        $set_up_meal_2 = Dict::getOptionsArrByName('加第二路宽带');
        $set_up_meal_3 = Dict::getOptionsArrByName('加卡');
        foreach ($data as $k => $row) {
            $data[$k]['is_new_user'] = $is_new_user[$row['is_new_user']];
            if($row['is_new_user']===0){
                $data[$k]['project_type']=$project_type_v[$row['project_type']];
            }else{
                $data[$k]['project_type']=$project_type_d[$row['project_type']];
            }
            $data[$k]['business_type'] = $business_type[$row['business_type']];
            $data[$k]['terminal_type'] = $terminal_type[$row['terminal_type']];

            $set_meal_arr=json_decode($row['set_meal'],true);
            $set_meal_info='';
            if($set_meal_arr['meal']['meal_type']===0){
                $meal_type=$set_meal_0[$set_meal_arr['meal']['meal']];
            }elseif($set_meal_arr['meal']['meal_type']===1){
                $meal_type=$set_meal_1[$set_meal_arr['meal']['meal']];
            }elseif($set_meal_arr['meal']['meal_type']===2){
                $meal_type=$set_meal_2[$set_meal_arr['meal']['meal']];
            }
            $set_meal_info='套餐：'.$meal_type;
            foreach($set_meal_arr['up_meal'] as $v){
                if($v['meal_type']===0){
                    $up_meal_type=$set_up_meal_0[$v['meal']];
                }elseif($v['meal_type']===1){
                    $up_meal_type=$set_up_meal_1[$v['meal']];
                }elseif($v['meal_type']===2){
                    $up_meal_type=$set_up_meal_2[$v['meal']];
                }elseif($v['meal_type']===3){
                    $up_meal_type=$set_up_meal_3[$v['meal']];
                }
                $set_meal_info=$set_meal_info.'、'.$up_meal_type;
            }
            $data[$k]['set_meal'] = $set_meal_info;
            $applicant = DB::table('users')->where('id',$row['applicant'])->value('name');
            $data[$k]['applicant'] = $applicant;
        }

        return response()->json(['result' => $data, 'total' => $count], 200);
    }
}
                     