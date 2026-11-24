<?php
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); session_start();
$host='localhost'; $dbname='sikoba'; $username='root'; $password='';
function out($d,$c=200){http_response_code($c);echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
function input(){ $x=json_decode(file_get_contents('php://input'),true);return is_array($x)?$x:[]; }
try{
$p=new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4",$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ddl=[
"CREATE TABLE IF NOT EXISTS app_state(id TINYINT UNSIGNED PRIMARY KEY,state_json LONGTEXT NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS obat(id BIGINT UNSIGNED PRIMARY KEY,nama_obat VARCHAR(150) NOT NULL,jenis VARCHAR(50) NOT NULL,dosis VARCHAR(80),stok INT NOT NULL DEFAULT 0,stok_minimum INT NOT NULL DEFAULT 0,tanggal_kedaluwarsa DATE,kategori VARCHAR(80),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_obat_nama(nama_obat)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS transaksi(id BIGINT UNSIGNED PRIMARY KEY,obat_id BIGINT UNSIGNED NOT NULL,jenis_transaksi ENUM('Masuk','Keluar') NOT NULL,jumlah INT NOT NULL,tanggal DATE NOT NULL,keterangan VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_tx_obat(obat_id),INDEX idx_tx_tanggal(tanggal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS petugas(id TINYINT UNSIGNED PRIMARY KEY,nama VARCHAR(150) NOT NULL,username VARCHAR(80) NOT NULL,email VARCHAR(150),no_hp VARCHAR(30),updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS users(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nama VARCHAR(150) NOT NULL,username VARCHAR(80) NOT NULL UNIQUE,email VARCHAR(150) NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,no_hp VARCHAR(30),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS kategori_obat(id INT AUTO_INCREMENT PRIMARY KEY,nama VARCHAR(80) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS notifikasi(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,obat_id BIGINT UNSIGNED NULL,jenis VARCHAR(50) NOT NULL,pesan VARCHAR(255) NOT NULL,status_baca TINYINT(1) NOT NULL DEFAULT 0,dibuat_pada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_notif_obat(obat_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS stok_opname(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nomor_opname VARCHAR(40) NOT NULL UNIQUE,tanggal DATE NOT NULL,keterangan VARCHAR(255) NULL,total_item INT NOT NULL DEFAULT 0,total_selisih INT NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'Selesai',dibuat_pada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_opname_tanggal(tanggal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS detail_stok_opname(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,opname_id BIGINT UNSIGNED NOT NULL,obat_id BIGINT UNSIGNED NOT NULL,nama_obat VARCHAR(150) NOT NULL,stok_sistem INT NOT NULL,stok_fisik INT NOT NULL,selisih INT NOT NULL,FOREIGN KEY(opname_id) REFERENCES stok_opname(id) ON DELETE CASCADE,INDEX idx_detail_opname_obat(obat_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
foreach($ddl as $q){$p->exec($q);}
$q=$p->prepare("SELECT id FROM users WHERE username=?");$q->execute(['petugas']);
if(!$q->fetch()){$s=$p->prepare("INSERT INTO users(nama,username,email,password_hash,no_hp) VALUES(?,?,?,?,?)");$s->execute(['Petugas Farmasi','petugas','petugas@sikoba.local',password_hash('sikoba123',PASSWORD_DEFAULT),'']);}
$a=$_GET['action']??'';
if($a==='register'&&$_SERVER['REQUEST_METHOD']==='POST'){
$d=input();$n=trim($d['name']??'');$u=trim($d['username']??'');$e=trim($d['email']??'');$pw=(string)($d['password']??'');$hp=trim($d['phone']??'');
if(!$n||!$u||strlen($pw)<4)out(['success'=>false,'message'=>'Nama, username, dan password minimal 4 karakter wajib diisi.'],422);
try{$s=$p->prepare("INSERT INTO users(nama,username,email,password_hash,no_hp) VALUES(?,?,?,?,?)");$s->execute([$n,$u,$e?:null,password_hash($pw,PASSWORD_DEFAULT),$hp]);}catch(PDOException $e){out(['success'=>false,'message'=>'Username atau email sudah digunakan.'],409);}
$_SESSION['user_id']=(int)$p->lastInsertId();out(['success'=>true,'user'=>['name'=>$n,'username'=>$u,'email'=>$e,'phone'=>$hp]]);
}
if($a==='login'&&$_SERVER['REQUEST_METHOD']==='POST'){
$d=input();$key=trim($d['username']??'');$pw=(string)($d['password']??'');$s=$p->prepare("SELECT * FROM users WHERE username=? OR email=? LIMIT 1");$s->execute([$key,$key]);$u=$s->fetch();
if(!$u||!password_verify($pw,$u['password_hash']))out(['success'=>false,'message'=>'Username/email atau password salah.'],401);
session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];out(['success'=>true,'user'=>['name'=>$u['nama'],'username'=>$u['username'],'email'=>$u['email'],'phone'=>$u['no_hp']]]);
}
if($a==='logout'){session_destroy();out(['success'=>true]);}
if($a==='session'){
if(empty($_SESSION['user_id']))out(['success'=>true,'loggedIn'=>false]);
$s=$p->prepare("SELECT nama,username,email,no_hp FROM users WHERE id=?");$s->execute([$_SESSION['user_id']]);$u=$s->fetch();if(!$u){session_destroy();out(['success'=>true,'loggedIn'=>false]);}
out(['success'=>true,'loggedIn'=>true,'user'=>['name'=>$u['nama'],'username'=>$u['username'],'email'=>$u['email'],'phone'=>$u['no_hp']]]);
}
if($a==='load'&&$_SERVER['REQUEST_METHOD']==='GET'){
$r=$p->query("SELECT state_json FROM app_state WHERE id=1")->fetch();$d=$r?json_decode($r['state_json'],true):[];if(!is_array($d))$d=[];
$d['medicines']=array_map(fn($m)=>['id'=>(int)$m['id'],'name'=>$m['nama_obat'],'type'=>$m['jenis'],'dose'=>$m['dosis'],'stock'=>(int)$m['stok'],'min'=>(int)$m['stok_minimum'],'expiry'=>$m['tanggal_kedaluwarsa'],'category'=>$m['kategori']],$p->query("SELECT * FROM obat ORDER BY id")->fetchAll());
$d['transactions']=array_map(fn($t)=>['id'=>(int)$t['id'],'medicineId'=>(int)$t['obat_id'],'type'=>$t['jenis_transaksi'],'qty'=>(int)$t['jumlah'],'date'=>$t['tanggal'],'note'=>$t['keterangan']],$p->query("SELECT * FROM transaksi ORDER BY tanggal DESC,id DESC")->fetchAll());
$opnames=$p->query("SELECT * FROM stok_opname ORDER BY tanggal DESC,id DESC")->fetchAll();
foreach($opnames as &$op){$q=$p->prepare("SELECT obat_id AS medicine_id,nama_obat,stok_sistem,stok_fisik,selisih FROM detail_stok_opname WHERE opname_id=? ORDER BY id");$q->execute([$op['id']]);$op['details']=$q->fetchAll();$op['id']=(int)$op['id'];$op['jumlah_item']=(int)$op['total_item'];$op['total_selisih']=(int)$op['total_selisih'];}
unset($op);$d['opnames']=$opnames;
if(!empty($_SESSION['user_id'])){$s=$p->prepare("SELECT nama,username,email,no_hp FROM users WHERE id=?");$s->execute([$_SESSION['user_id']]);$u=$s->fetch();if($u)$d['user']=['name'=>$u['nama'],'username'=>$u['username'],'email'=>$u['email'],'phone'=>$u['no_hp']];}
elseif(!isset($d['user']))$d['user']=['name'=>'Petugas Farmasi','username'=>'petugas','email'=>'petugas@sikoba.local','phone'=>''];
$d['loggedIn']=!empty($_SESSION['user_id']);out(['success'=>true,'data'=>$d]);
}
if($a==='opname'&&$_SERVER['REQUEST_METHOD']==='POST'){
if(empty($_SESSION['user_id']))out(['success'=>false,'message'=>'Silakan login terlebih dahulu.'],401);
$d=input();$tanggal=$d['tanggal']??date('Y-m-d');$keterangan=trim($d['keterangan']??'');$items=$d['items']??[];
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$tanggal)||!is_array($items)||count($items)<1)out(['success'=>false,'message'=>'Tanggal dan minimal satu data obat wajib diisi.'],422);
$p->beginTransaction();
try{
$nomor='OPN-'.date('YmdHis').'-'.random_int(100,999);
$s=$p->prepare("INSERT INTO stok_opname(nomor_opname,tanggal,keterangan,total_item,total_selisih,status) VALUES(?,?,?,?,?,'Selesai')");
$detailStmt=$p->prepare("INSERT INTO detail_stok_opname(opname_id,obat_id,nama_obat,stok_sistem,stok_fisik,selisih) VALUES(?,?,?,?,?,?)");
$update=$p->prepare("UPDATE obat SET stok=? WHERE id=?");
$details=[];$totalSelisih=0;$seen=[];
foreach($items as $item){
$id=(int)($item['medicine_id']??0);$fisik=$item['stok_fisik']??null;
if($id<1||!is_numeric($fisik)||!is_finite((float)$fisik)||floor((float)$fisik)!=(float)$fisik||(int)$fisik<0||isset($seen[$id]))throw new RuntimeException('Data stok fisik tidak valid atau obat terduplikasi.');
$seen[$id]=true;
$q=$p->prepare("SELECT id,nama_obat,stok FROM obat WHERE id=? FOR UPDATE");$q->execute([$id]);$obat=$q->fetch();
if(!$obat)throw new RuntimeException('Salah satu data obat tidak ditemukan. Muat ulang halaman dan coba kembali.');
$system=(int)$obat['stok'];$physical=(int)$fisik;$diff=$physical-$system;
$details[]=['medicine_id'=>$id,'nama_obat'=>$obat['nama_obat'],'stok_sistem'=>$system,'stok_fisik'=>$physical,'selisih'=>$diff];$totalSelisih+=$diff;
}
$s->execute([$nomor,$tanggal,$keterangan?:null,count($details),$totalSelisih]);$opnameId=(int)$p->lastInsertId();
foreach($details as $detail){$detailStmt->execute([$opnameId,$detail['medicine_id'],$detail['nama_obat'],$detail['stok_sistem'],$detail['stok_fisik'],$detail['selisih']]);$update->execute([$detail['stok_fisik'],$detail['medicine_id']]);}
$p->commit();
out(['success'=>true,'opname'=>['id'=>$opnameId,'nomor'=>$nomor,'tanggal'=>$tanggal,'keterangan'=>$keterangan,'jumlah_item'=>count($details),'total_selisih'=>$totalSelisih,'status'=>'Selesai','details'=>$details],'details'=>$details]);
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
if($a==='save'&&$_SERVER['REQUEST_METHOD']==='POST'){
$d=input();if(!$d)out(['success'=>false,'message'=>'Data JSON tidak valid.'],400);$p->beginTransaction();
$state=$d;$state['loggedIn']=false;$s=$p->prepare("INSERT INTO app_state(id,state_json) VALUES(1,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json)");$s->execute([json_encode($state,JSON_UNESCAPED_UNICODE)]);
$up=$p->prepare("INSERT INTO obat(id,nama_obat,jenis,dosis,stok,stok_minimum,tanggal_kedaluwarsa,kategori) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama_obat=VALUES(nama_obat),jenis=VALUES(jenis),dosis=VALUES(dosis),stok=VALUES(stok),stok_minimum=VALUES(stok_minimum),tanggal_kedaluwarsa=VALUES(tanggal_kedaluwarsa),kategori=VALUES(kategori)");
$ids=[];$cats=[];foreach(($d['medicines']??[]) as $m){$id=(int)($m['id']??0);if($id<1)continue;$cat=trim($m['category']??'');$up->execute([$id,$m['name']??'-',$m['type']??'Tablet',$m['dose']??null,(int)($m['stock']??0),(int)($m['min']??0),($m['expiry']??'')?:null,$cat?:null]);$ids[]=$id;if($cat)$cats[$cat]=1;}
if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$s=$p->prepare("DELETE FROM obat WHERE id NOT IN ($marks)");$s->execute($ids);}else $p->exec("DELETE FROM obat");
$tx=$p->prepare("INSERT INTO transaksi(id,obat_id,jenis_transaksi,jumlah,tanggal,keterangan) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE obat_id=VALUES(obat_id),jenis_transaksi=VALUES(jenis_transaksi),jumlah=VALUES(jumlah),tanggal=VALUES(tanggal),keterangan=VALUES(keterangan)");
foreach(($d['transactions']??[]) as $t)$tx->execute([(int)$t['id'],(int)$t['medicineId'],($t['type']??'Masuk')==='Keluar'?'Keluar':'Masuk',(int)$t['qty'],$t['date']??date('Y-m-d'),$t['note']??null]);
foreach(array_keys($cats) as $c){$s=$p->prepare("INSERT IGNORE INTO kategori_obat(nama) VALUES(?)");$s->execute([$c]);}
if(!empty($_SESSION['user_id'])&&!empty($d['user'])){$u=$d['user'];$s=$p->prepare("UPDATE users SET nama=?,email=?,no_hp=? WHERE id=?");$s->execute([$u['name']??'Petugas',$u['email']??null,$u['phone']??null,$_SESSION['user_id']]);}
if(!empty($d['user'])){$u=$d['user'];$s=$p->prepare("INSERT INTO petugas(id,nama,username,email,no_hp) VALUES(1,?,?,?,?) ON DUPLICATE KEY UPDATE nama=VALUES(nama),username=VALUES(username),email=VALUES(email),no_hp=VALUES(no_hp)");$s->execute([$u['name']??'Petugas',$u['username']??'petugas',$u['email']??null,$u['phone']??null]);}
$p->exec("DELETE FROM notifikasi");$all=$p->query("SELECT id,nama_obat,stok,stok_minimum,tanggal_kedaluwarsa FROM obat")->fetchAll();$ni=$p->prepare("INSERT INTO notifikasi(obat_id,jenis,pesan) VALUES(?,?,?)");
foreach($all as $m){if((int)$m['stok']<=(int)$m['stok_minimum'])$ni->execute([$m['id'],'Stok Menipis','Stok '.$m['nama_obat'].' tersisa '.$m['stok']]);if($m['tanggal_kedaluwarsa']){$days=(int)floor((strtotime($m['tanggal_kedaluwarsa'])-strtotime(date('Y-m-d')))/86400);if($days<=30)$ni->execute([$m['id'],'Kedaluwarsa',$m['nama_obat'].($days<0?' sudah kedaluwarsa':' akan kedaluwarsa dalam '.$days.' hari')]);}}
$p->commit();out(['success'=>true,'message'=>'Semua data aplikasi berhasil disinkronkan ke MySQL.']);
}
out(['success'=>false,'message'=>'Aksi tidak dikenal.'],400);
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();error_log('SIKOBA API: '.$e->getMessage());out(['success'=>false,'message'=>'Kesalahan server/database: '.$e->getMessage()],500);}
?>