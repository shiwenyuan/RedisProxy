# RedisProxy
## use 
```php
aaaaa

$redis = \RedisProxy\RedisConnector::getInstance();
/********************string*************************/
$redis->set('zhangsan', '66666');//添加一个Key
$zhangsan = $redis->get('zhangsan');//获取一个key
$this->assertEquals($zhangsan, '66666');
$this->assertEquals($redis->del('zhangsan'), 1);//删除一个key

/*********************hash***************************/
$redis->hSet('key', 'field1', 'val1');//塞入一个值
$redis->hSet('key', 'field2', 'val2');
$redis->hSet('key', 'field3', 'val3');
$redis->hSet('key', 'field4', 'val4');
$data = $redis->hGetAll('key');//获取key 下所有值
$data1 = [
    'field1' => 'val1',
    'field2' => 'val2',
    'field3' => 'val3',
    'field4' => 'val4',
];
$this->assertEquals($data, $data1);
$this->assertEquals($redis->hGet('key','field1'), 'val1');//获取一个值
$this->assertEquals($redis->hDel('key','field1'), 1);
$this->assertEquals($redis->hDel('key','field5'), 0);//删除一个值
$this->assertEquals($redis->hMSet('testkey2',['zhangsan'=>1,['lisi'=>1]]), true); //添加多个值
$this->assertEquals($redis->hVals('key'), ['val2','val3','val4']);//获取hash的所有值
/*************************clear db*****************/
$this->assertEquals($redis->flushDB(),1);//清空数据库

/********************************list***************************/
$redis->lPush('listTestKey', '01');//从列表左侧追加一个值
$redis->lPush('listTestKey', '02');
$redis->lPush('listTestKey', '03');
$this->assertEquals($redis->lLen('listTestKey'),3);
$this->assertEquals($redis->rPop('listTestKey'),'01');//从列表右侧弹出一个值
$this->assertEquals($redis->lLen('listTestKey'),2);//获取列表长度
$this->assertEquals($redis->rPush('listTestKey','04'), 3);//向列表右侧追加一个值
$this->assertEquals($redis->blPop(['listTestKey']),['listTestKey','03']);//从列表左侧弹出一个值
$this->assertEquals($redis->lRange('listTestKey',0,-1), [ 02, 04]);//获取列表所有值
$this->assertEquals($redis->lRange('listTestKey',0,0), [2]);//获取列表指定区间值
$redis->lPush('listTestKey', '02');
$redis->lPush('listTestKey', '02');
$this->assertEquals($redis->lRem('listTestKey',2,'02'), 2);//删除两个value 为02的数据
$this->assertEquals($redis->lIndex('listTestKey', 0),2);//获取指定下标的value
$this->assertEquals($redis->lIndex('listTestKey', 2),false);

/*******************************zset********************************/
$redis->zAdd('zSetTestKey',1,'zhangsan');//添加一个集合
$redis->zAdd('zSetTestKey',2,'lisi');
$redis->zAdd('zSetTestKey',3,'wangwu');
$redis->zAdd('zSetTestKey',4,'xiaoming');
$this->assertEquals($redis->zAdd('zSetTestKey',5,'xiaoming2'),1);
$this->assertEquals($redis->zRange('zSetTestKey', 0, -1),['zhangsan','lisi','wangwu','xiaoming','xiaoming2']);//正序排列指定集合
$this->assertEquals($redis->zRangeByScore('zSetTestKey',3,5),['wangwu','xiaoming','xiaoming2']);
$this->assertEquals($redis->zCard('zSetTestKey'), 5);//获取集合长度
$this->assertEquals($redis->zRem('zSetTestKey','xiaoming2'),1);//删除指定val
$this->assertEquals($redis->zRevRange('zSetTestKey', 0, -1),['xiaoming','wangwu','lisi','zhangsan']);//倒序排列指定集合

$this->assertEquals($redis->getSet('getSetTest','zhangssa222n01'),true);//如果KEY不存咋则添加一个KEY 存在则修改
$this->assertEquals($redis->setnx('getSetTest','zhangssa2ss2n01'),true);//如果KEY不存咋则添加一个KEY 存在则不修改
$this->assertEquals($redis->get('getSetTest'),'zhangssa222n01');
$redis->set('getSetTest01','zhangssss');
$this->assertEquals($redis->get('getSetTest01'),'zhangssss');


```
