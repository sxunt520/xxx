<?php
/**
     *进行点赞
     */
    public function actionLike(){
        Yii::$app->response->format=Response::FORMAT_JSON;
        $data = @json_decode(file_get_contents("php://input","r"),true);
        $topic_id=$data['topic_id'];
        $like = new LikeLog();
        if(Yii::$app->request->isGet){
            return [
                'msg'=>'Request Error!',
                'state'=>(int)-1,
            ];
        }
        $result=$like->apiLike($topic_id);//数据库里去更新点赞数，存入缓存
        if ($result && Yii::$app->cache->exists($topic_id)){
            return [
                'msg'=>'ok',
                'state'=>(int)0,
                'likes'=>(int)Yii::$app->cache->get($topic_id),
            ];
        }else{//缓存中都没有，初次访问然后去库中取
            $data=self::likes($topic_id);
            if (!empty($like->error)){
                $data['msg']=$like->error;
            }
            return $data;
        }
    }

    /**
     *获取点赞条数
     */
    public function actionGetlikes(){

        Yii::$app->response->format=Response::FORMAT_JSON;
        if (Yii::$app->request->isPost){
            return [
                'msg'=>'Request Error!',
                'state'=>(int)-1,
            ];
        }
        $topic_id=Yii::$app->request->get('topic_id');
        if (Yii::$app->cache->exists($topic_id)){
            $likes=Yii::$app->cache->get($topic_id);
            return [
                'msg'=>'ok',
                'state'=>(int)0,
                'likes'=>$likes,
//                'from'=>'cache',
            ];
        }else{
            return self::likes($topic_id);
        }
    }


	//初次访问，存入缓存中返回
    private static function likes($topic_id){
        $content=MediaCloudContent::find()->where(['topic_id'=>$topic_id])->select(['topic_id','likes'])->asArray()->one();
        if (!$content){
            return [
                'msg'=>'话题不存在',
                'state'=>'-1',
            ];
        }
        Yii::$app->cache->set($topic_id,(int)$content['likes']);
        return [
            'msg'=>'ok',
            'state'=>(int)0,
            'likes'=>$content['likes'],
//                'from'=>'sql',
        ];
    }


//库里更新点赞数，事务控制 成功点赞数存入缓存 Like_log
 public function apiLike($topic_id){
        $content=MediaCloudContent::find()->where(['topic_id'=>$topic_id])->select(['topic_id','likes'])->asArray()->one();
        if (empty($content)){
            $this->error='话题不存在！';
            return false;
        }
        $r = $this->findOne(['topic_id'=>$topic_id,'ip'=>Yii::$app->request->getUserIP()],'created desc');
        if ($r && time()-($r->created) < 10){
            $this->error='两次点赞间隔不能低于10秒';
            return false;
        }else{
            $transaction=Yii::$app->db->beginTransaction();
            try{
                $this->topic_id = $topic_id;
                $this->save();//保存日志 过滤器还保存了一个ip
                //锁定行
                $sql="select likes from {{%mediacloud_content}} where topic_id='$topic_id' for update";
                $data=Yii::$app->db->createCommand($sql)->query()->read();
                $sql="update {{%mediacloud_content}} set likes=likes+1 where topic_id='$topic_id'";
                Yii::$app->db->createCommand($sql)->execute();
                $transaction->commit();
                Yii::$app->cache->set($topic_id,$data['likes']+1);
            }catch (Exception $e){
                Yii::error($e->getMessage());
                $this->error=json_encode($e->getMessage());
                $transaction->rollBack();
                return false;
            }

        }
        return true;
    }