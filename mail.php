<?php
/////////////////////////////////////////
//メールを受信した際、起動するPHPファイル//
//    メールの内容を解析しDBに登録      //
///////////////////////////////////////      
require_once("DB.php"); //DB接続情報の読み込み
//PEAR の Mail/mimeDecode.php を読み込む
require_once 'Mail/mimeDecode.php';
require_once 'Mail/mime.php';

//変数定義
$MailBody = "";

    /**
    * メールデータを解析する
    * @param $mailTxt メールデータ
    * @return メールの解析結果
    */

    //標準入力で取得
    $stdin = file_get_contents("php://stdin");

        $params = [];
        $params['include_bodies'] = true; //返却されるデータにメール本体を含むかどうか
        $params['decode_bodies']  = true; //返却されるデータのメール本体をデコードするかどうか
        $params['decode_headers'] = true; //返却されるデータのメールヘッダーをデコードするかどうか
        $params['crlf'] = "\r\n";         //改行コードの指定
 
        //メール本文を設定 テストのためtxtにメールの内容を入れているが本来は標準入力
        $params['input'] = $mailTxt;
 
        	$structure = Mail_mimeDecode::decode($params);

//--------------------------------------------------
//メールアドレスの取得
//--------------------------------------------------
$myMail = mb_convert_encoding(mb_decode_mimeheader($structure->headers['from']), 'UTF-8', 'ISO-2022-JP');

//--------------------------------------------------
// 件名を取得
//--------------------------------------------------
$diary_subject = mb_convert_encoding($structure->headers['subject'], 'UTF-8', 'ISO-2022-JP');
if ($diary_subject == "") {
    $diary_subject = "--";
}
if ($diary_subject == null) {
    $diary_subject = "--";
}
switch (strtolower($structure->ctype_primary)) {
    case "text": // シングルパート(テキストのみ)
        //gmailはUTF-8で送信されているので、エンコード処理しない
        $pos = strpos($myMail, "@gmail.com");
        if ($pos === false) {
            $diary_body = mb_convert_encoding($structure->body, 'UTF-8', 'ISO-2022-JP');
 
        } else {
            $diary_body = $structure->body;
        }

        break;
    case "multipart": // マルチパート(画像付き)
    
        foreach ($structure->parts as $part) {
        
            switch (strtolower($part->ctype_primary)) {
                case "text": // テキスト
                    $pos = strpos($myMail, "@gmail.com");
                    if ($pos === false) {
                        $diary_body = mb_convert_encoding($part->body, 'UTF-8', 'ISO-2022-JP');
                        
                    } else {
                        $diary_body = $part->body;
                    }
                    
                    break;
            }
            break;
        }
        break;
    default:
        $diary_body = "nofile";
        break;
}

//--------------------------------------------------
// 必要な情報を解析(共通処理)
//--------------------------------------------------

//改行を区切りとして配列に入れる
$array = explode("\n", $diary_body);


for($i = 0; $i < count($array); $i++){
    
    //余分な空白削除
    $MailBody = trim($array[$i]);
   
    //メルカリのメールかどうかの判定
    if(strpos($array[$i], "メルカリ") !== false){
            
        $site_name = "メルカリ";
        
    }
    
    //商品IDを変数に格納
    if(strpos($array[$i], "商品ID") !== false){
            
        //必要な部分以外はトリムし変数に格納
        $mid = str_replace('商品ID : ', '',$MailBody);
        var_dump($mid);
    }
    
    //商品名を変数に格納
    if(strpos($array[$i], "商品名") !== false){
        
        //必要な部分以外はトリムし変数に格納
        $item_name = str_replace('商品名 : ', '',$MailBody);
        //末尾に付与されているidを格納
        $item_id = str_replace('】', '',mb_substr($item_name,-7));
        var_dump($item_name);
        
    } 
    
    //商品価格を変数に格納
    if(strpos($array[$i], "商品価格") !== false){
            
        //必要な部分以外はトリムし変数に格納
        $item_price = str_replace('商品価格 : ', '',$MailBody);
        $item_price = str_replace('円', '',$item_price);
        var_dump($item_price);
        
    } 
//--------------------------------------------------
// 必要な情報を解析(購入メール) 購入
//--------------------------------------------------    
    
     //購入者名を変数に格納
    if(strpos($array[$i], "下記の商品を") !== false && strpos($array[$i], "さんが購入しました。") !== false){
            
        //必要な部分以外はトリムし変数に格納
        $buyer_name = str_replace('下記の商品を', '',$MailBody);
        $buyer_name = str_replace('さんが購入しました。', '',$buyer_name);
        
        //ステータスを0:購入にする (購入時のみ履歴テーブル(histories)に残す
        $status = 0;
        
        //区別を未到着にする
        $kind = 1;
        
    }
//--------------------------------------------------
// 必要な情報を解析(取引完了メール) 到着済み
//--------------------------------------------------

   //取引完了のメールかどうかの判定
    if(strpos($array[$i], "下記の商品の取引が完了") !== false){
                    
        //区別を到着済みにする
        $kind = 2;
        
    }
    
//--------------------------------------------------
// 必要な情報を解析(取引キャンセルメール) 返品
//--------------------------------------------------

   //取引完了のメールかどうかの判定
    if(strpos($array[$i], "キャンセル申請成立") !== false){
                    
        //区別を返品にする
        $kind = 3;
        
    }
}
//////////////////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////下記DB処理//////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////////////////////////
    
       try{
           //DB名を引数として渡す
           $pdo = db("mercari");
           
//--------------------------------------------------
//              返品メールの処理
//--------------------------------------------------
            //返品メールの場合の処理
            if($kind == 3 && $site_name == "メルカリ"){
                
                //トランザクション開始
                $pdo->beginTransaction();
                //ステータスを1到着済みに更新
                $stmt= $pdo->prepare ("UPDATE mercaris SET  shorikubun = 3 WHERE mid = :mid");

                //値をセット
                $stmt->bindParam(':mid', $mid);
            
                //クエリ実行
                $ret = $stmt->execute();
//------------------------
//　　　　在庫調整
//------------------------
                //購入があったら商品情報テーブルの在庫を-1する
                $stmt= $pdo->prepare ("UPDATE items SET  stock = stock+1 WHERE item_id = :item_id");

                //値をセット
                $stmt->bindParam(':item_id', $item_id);

                //クエリ実行
                $ret = $stmt->execute();
            }
//--------------------------------------------------
//              取引完了メールの処理
//--------------------------------------------------
            
            //取引完了メールの場合の処理
            if($kind == 2 && $site_name == "メルカリ"){
                //トランザクション開始
                $pdo->beginTransaction();
                //ステータスを1到着済みに更新
                $stmt= $pdo->prepare ("UPDATE mercaris SET  shorikubun = 2 WHERE mid = :mid");

                //値をセット
                $stmt->bindParam(':mid', $mid);


                //クエリ実行
                $ret = $stmt->execute();
            }
//--------------------------------------------------
//              購入メールの処理
//--------------------------------------------------            
            //購入メールの場合の処理
            if($kind == 1 && $site_name == "メルカリ"){
            
                //トランザクション開始
                $pdo->beginTransaction();
                // SQL作成
                $stmt= $pdo->prepare ("INSERT INTO mercaris (
                mid, item_name, item_price,buyer_name,shorikubun,created_at
                ) VALUES (
                :mid, :item_name, :item_price,:buyer_name,:shorikubun,CURRENT_TIMESTAMP)");

                //値をセット
                $stmt->bindParam(':mid', $mid);
                $stmt->bindParam(':item_name', $item_name);
                $stmt->bindParam(':item_price', $item_price);
                $stmt->bindParam(':buyer_name', $buyer_name);
                $stmt->bindParam(':shorikubun', $kind);

                //クエリ実行
                $ret = $stmt->execute();
            
//------------------------
//　　　　在庫調整
//------------------------

                //購入があったら商品情報テーブルの在庫を-1する
                $stmt= $pdo->prepare ("UPDATE items SET  stock = stock-1 WHERE item_id = :item_id");

                //値をセット
                $stmt->bindParam(':item_id', $item_id);


                //クエリ実行
                $ret = $stmt->execute();
            
//------------------------
//　　売上テーブルを更新
//------------------------
                //購入があったら売上テーブルの売り上げ数を+1する
                $stmt= $pdo->prepare ("UPDATE solds SET  sold_count = sold_count+1 WHERE item_id = :item_id");

                //値をセット
                $stmt->bindParam(':item_id', $item_id);


                //クエリ実行
                $ret = $stmt->execute();
//------------------------------------------
//履歴テーブルのインサートする際の必要情報取得
//------------------------------------------
                //商品情報テーブル(items)からid(履歴テーブルと関連付け)、在庫数(historiesに在庫数インサート)取得
                $stmt= $pdo->prepare ("SELECT * FROM items WHERE item_id = :item_id");

                //値をセット
                $stmt->bindParam(':item_id', $item_id);


                //クエリ実行
                $ret = $stmt->execute();     

                // 該当するデータを取得
                if( $ret ) {
                    $data = $stmt->fetch();
                    $stock  = $data['stock'];//在庫数

                }
//--------------------------------
//履歴(history)テーブルにインサート
//--------------------------------    
                //履歴(history)テーブルにインサート
                $stmt= $pdo->prepare ("INSERT INTO histories (
                 item_name, item_price,site_name, status,stock,item_id,created_at
                ) VALUES (
                :item_name, :item_price,:site_name,:status,:stock,:item_id,CURRENT_TIMESTAMP)");

                //値をセット
                $stmt->bindParam(':item_name', $item_name);
                $stmt->bindParam(':item_price', $item_price);
                $stmt->bindParam(':site_name', $site_name);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':stock', $stock);
                $stmt->bindParam(':item_id', $item_id);

                var_dump($item_id);

                //クエリ実行
                $ret = $stmt->execute();            
            }

                if (!$ret) {
                    throw new Exception('INSERT 失敗');
                }
            //commit
            $pdo->commit();

       } catch (PDOException $e) {//処理がうまくいかなければキャッチ
            //rollback
            $pdo->rollBack();
            echo "ロールバック";
            die(mb_convert_encoding($e->getMessage(), 'UTF-8','SJIS-win'));
       }

        //DB切断
        $pdo = null;
   

?>
