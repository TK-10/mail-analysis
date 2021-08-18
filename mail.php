<?php
/////////////////////////////////////////
//���[������M�����ہA�N������PHP�t�@�C��//
//    ���[���̓��e����͂�DB�ɓo�^      //
///////////////////////////////////////      
require_once("DB.php"); //DB�ڑ����̓ǂݍ���
//PEAR �� Mail/mimeDecode.php ��ǂݍ���
require_once 'Mail/mimeDecode.php';
require_once 'Mail/mime.php';

//�ϐ���`
$MailBody = "";
$shorikubun = 1; //�w�����͖������̂���1��ݒ�


    /**
    * ���[���f�[�^����͂���
    * @param $mailTxt ���[���f�[�^
    * @return ���[���̉�͌���
    */
    //�W�����͂Ŏ擾
    $mailTxt = file_get_contents('php://stdin');
        $params = [];
        $params['include_bodies'] = true; //�ԋp�����f�[�^�Ƀ��[���{�̂��܂ނ��ǂ���
        $params['decode_bodies']  = true; //�ԋp�����f�[�^�̃��[���{�̂��f�R�[�h���邩�ǂ���
        $params['decode_headers'] = true; //�ԋp�����f�[�^�̃��[���w�b�_�[���f�R�[�h���邩�ǂ���
        $params['crlf'] = "\r\n";         //���s�R�[�h�̎w��
 
        //���[���{����ݒ� �e�X�g�̂���txt�Ƀ��[���̓��e�����Ă��邪�{���͕W������
        $params['input'] = $mailTxt;
 
        	$structure = Mail_mimeDecode::decode($params);

//--------------------------------------------------
//���[���A�h���X�̎擾
//--------------------------------------------------
$myMail = mb_convert_encoding(mb_decode_mimeheader($structure->headers['from']), 'UTF-8', 'ISO-2022-JP');

//--------------------------------------------------
// �������擾
//--------------------------------------------------
$diary_subject = mb_convert_encoding($structure->headers['subject'], 'UTF-8', 'ISO-2022-JP');
if ($diary_subject == "") {
    $diary_subject = "--";
}
if ($diary_subject == null) {
    $diary_subject = "--";
}
switch (strtolower($structure->ctype_primary)) {
    case "text": // �V���O���p�[�g(�e�L�X�g�̂�)
        //gmail��UTF-8�ő��M����Ă���̂ŁA�G���R�[�h�������Ȃ�
        $pos = strpos($myMail, "@gmail.com");
        if ($pos === false) {
            $diary_body = mb_convert_encoding($structure->body, 'UTF-8', 'ISO-2022-JP');
 
        } else {
            $diary_body = $structure->body;
        }

        break;
    case "multipart": // �}���`�p�[�g(�摜�t��)
    
        foreach ($structure->parts as $part) {
        
            switch (strtolower($part->ctype_primary)) {
                case "text": // �e�L�X�g
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
// �K�v�ȏ������
//--------------------------------------------------

//���s����؂�Ƃ��Ĕz��ɓ����
$array = explode("\n", $diary_body);


for($i = 0; $i < count($array); $i++){
    
    //�]���ȋ󔒍폜
    $MailBody = trim($array[$i]);
    
    //���iID��ϐ��Ɋi�[
    if(strpos($array[$i], "���iID") !== false){
            
        //�K�v�ȕ����ȊO�̓g�������ϐ��Ɋi�[
        $mid = str_replace('���iID : ', '',$MailBody);
    }
    
    //���i����ϐ��Ɋi�[
    if(strpos($array[$i], "���i��") !== false){
        
        //�K�v�ȕ����ȊO�̓g�������ϐ��Ɋi�[
        $item_name = str_replace('���i�� : ', '',$MailBody); 
    } 
    //���i���i��ϐ��Ɋi�[
    if(strpos($array[$i], "���i���i") !== false){
            
        //�K�v�ȕ����ȊO�̓g�������ϐ��Ɋi�[
        $item_price = str_replace('���i���i : ', '',$MailBody);
        $item_price = str_replace('�~', '',$item_price);
    } 
    //�w���Җ���ϐ��Ɋi�[
    if(strpos($array[$i], "���L�̏��i��") !== false && strpos($array[$i], "���񂪍w�����܂����B") !== false){
            
        //�K�v�ȕ����ȊO�̓g�������ϐ��Ɋi�[
        $buyer_name = str_replace('���L�̏��i��', '',$MailBody);
        $buyer_name = str_replace('���񂪍w�����܂����B', '',$buyer_name);
    }
}

   //�w�����[���̏ꍇ�A�C���T�[�g�������s��
   if(isset($buyer_name)){
       
       try{

            $pdo = db("mercari");//DB���������Ƃ��ēn��
            //�g�����U�N�V�����J�n
            $pdo->beginTransaction();
            // SQL�쐬
            $stmt= $pdo->prepare ("INSERT INTO mercaris (
            mid, item_name, item_price,buyer_name,shorikubun,created_at
            ) VALUES (
            :mid, :item_name, :item_price,:buyer_name,:shorikubun)");
        
            //�l���Z�b�g
            $stmt->bindParam(':mid', $mid);
            $stmt->bindParam(':item_name', $item_name);
            $stmt->bindParam(':item_price', $item_price);
            $stmt->bindParam(':buyer_name', $buyer_name);
            $stmt->bindParam(':shorikubun', $shorikubun);
            
            //�N�G�����s
            $ret = $stmt->execute();
            
            if (!$ret) {
                throw new Exception('INSERT ���s');
            }

            //commit
            $pdo->commit();

       } catch (PDOException $e) {//���������܂������Ȃ���΃L���b�`
            //rollback
            $pdo->rollBack();
            echo "���[���o�b�N";
            die(mb_convert_encoding($e->getMessage(), 'UTF-8','SJIS-win'));
       }

        //DB�ؒf
        $pdo = null;
   }
?>
