<?php
////////////////////////////////////////////
///メール受信した際、起動するPHPファイル///
///    メールの内容を解析しDBに登録     ///
//////////////////////////////////////////

require_once("DB.php"); //DB接続情報の読み込み
//PEAR の Mail/mimeDecode.php を読み込む
require_once 'Mail/mimeDecode.php';
require_once 'Mail/mime.php';

//変数定義
$MailBody = "";
$shorikubun = 1; //購入時は未発送のため1を設定


    /**
    * メールデータを解析する
    * @param $mailTxt メールデータ
    * @return メールの解析結果
    */
    //標準入力で取得
    $mailTxt = file_get_contents('php://stdin');
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
// 必要な情報を解析
//--------------------------------------------------

//改行を区切りとして配列に入れる
$array = explode("\n", $diary_body);


for($i = 0; $i < count($array); $i++){
    
    //余分な空白削除
    $MailBody = trim($array[$i]);
    
    //商品IDを変数に格納
    if(strpos($array[$i], "商品ID") !== false){
            
        //必要な部分以外はトリムし変数に格納
        $mid = str_replace('商品ID : ', '',$MailBody);
    }
    
    //商品名を変数に格納
    if(strpos($array[$i], "商品名") !== false){
        
        //必要な部分以外はトリムし変数に格納
        $item_name = str_replace('商品名 : ', '',$MailBody); 
    } 
    
    //商品価格を変数に格納
    if(strpos($array[$i], "商品価格") !== false){
            
        //必要な部分以外はトリムし変数に格納
        $item_price = str_replace('商品価格 : ', '',$MailBody);
        $item_price = str_replace('円', '',$item_price);
    } 
    
    //購入者名を変数に格納
    if(strpos($array[$i], "下記の商品を") !== false && strpos($array[$i], "さんが購入しました。") !== false){
            
        //必要な部分以外はトリムし変数に格納
        $buyer_name = str_replace('下記の商品を', '',$MailBody);
        $buyer_name = str_replace('さんが購入しました。', '',$buyer_name);
    }
}

   //購入メールの場合、インサート処理を行う
   if(isset($buyer_name)){
       
       try{

            $pdo = db("mercari");//DB名を引数として渡す
            //トランザクション開始
            $pdo->beginTransaction();
            // SQL作成
            $stmt= $pdo->prepare ("INSERT INTO mercaris (
            mid, item_name, item_price,buyer_name,shorikubun,created_at
            ) VALUES (
            :mid, :item_name, :item_price,:buyer_name,:shorikubun)");
        
            //値をセット
            $stmt->bindParam(':mid', $mid);
            $stmt->bindParam(':item_name', $item_name);
            $stmt->bindParam(':item_price', $item_price);
            $stmt->bindParam(':buyer_name', $buyer_name);
            $stmt->bindParam(':shorikubun', $shorikubun);
            
            //クエリ実行
            $ret = $stmt->execute();
            
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
   }
?>
