<?php

/**
 * 通过ip或hostname获取地区信息
 **/
class Ip2location
{
    static $ip = '';

    function __construct()
    {
        $this->ip = new monip();
    }

    function find($ip_or_host){
        $res = $this->ip->find($ip_or_host);
        list($country, $province, $city, $district,$isp, $lat, $lag,$timezone_name, $timezone,$zip,$phonecode,$countrycode,$region)= explode('\t',$res);
        $result = array(
            'country'=>$country, 'province'=>$province, 'city'=>$city, 'district'=>$district,
            'isp'=>$isp, 'lat'=>$lat, 'lag'=>$lag,
            'timezone_name'=>$timezone_name, 'timezone'=>$timezone,
            'zip'=>$zip, 'phonecode'=>$phonecode, 'countrycode'=>$countrycode, 'region'=>$region
        );
        return $result;
    }
}
