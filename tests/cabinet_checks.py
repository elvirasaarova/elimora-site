"""Integration checks against an isolated PHP server and a disposable CI MySQL database."""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import urllib.parse

ROOT=Path(__file__).resolve().parents[1]


def check_allowlist():
    import runpy
    public=runpy.run_path(str(ROOT/'scripts/deploy_beget.py'))['public_file']
    for n in ['index.html','cabinet/api.php','cabinet/bootstrap.php','cabinet/schema.sql','cabinet/.htaccess','assets/portrait.webp']:
        assert public(n),n
    for n in ['.env','elimora-config.php','scripts/elimora-config.example.php','cabinet/config.php','cabinet/uploads/private.pdf','uploads/a.pdf','../a.json','/a.json','.git/config']:
        assert not public(n),n


class Client:
    def __init__(self,url):self.url=url;self.cookie='';self.csrf=''
    def request(self,action,data=None,extra='',csrf=True,raw=None):
        headers={}
        if self.cookie:headers['Cookie']=self.cookie
        body=None
        if data is not None:
            body=json.dumps(data).encode();headers['Content-Type']='application/json'
        if raw is not None:body,headers['Content-Type']=raw
        if body is not None and csrf:headers['X-CSRF-Token']=self.csrf
        request=urllib.request.Request(self.url+'/cabinet/api.php?action='+action+extra,data=body,headers=headers)
        try:r=urllib.request.urlopen(request)
        except urllib.error.HTTPError as e:r=e
        if r.headers.get('Set-Cookie'):self.cookie=r.headers['Set-Cookie'].split(';')[0]
        payload=r.read()
        if r.headers.get_content_type()=='application/octet-stream':return r.status,payload
        result=json.loads(payload)
        if result.get('csrf'):self.csrf=result['csrf']
        return r.status,result


def integration():
    with tempfile.TemporaryDirectory() as directory:
        base=Path(directory);public=base/'public_html';shutil.copytree(ROOT/'cabinet',public/'cabinet')
        config={'db_host':os.environ.get('CI_DB_HOST','127.0.0.1'),'db_name':'elimora_test','db_user':'root','db_password':os.environ['CI_DB_PASSWORD'],'install_key':'a'*64,'admin_email':'admin@example.test'}
        # PHP's JSON decoder avoids embedding any interpolated PHP string values.
        payload=base64.b64encode(json.dumps(config).encode()).decode()
        (base/'elimora-config.php').write_text("<?php return json_decode(base64_decode('"+payload+"'),true);")
        router=base/'router.php';router.write_text("<?php $_SERVER['HTTPS']='on'; return false;")
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(public),str(router)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            url=f'http://127.0.0.1:{port}'
            admin=Client(url)
            for _ in range(40):
                try:status,s=admin.request('status');break
                except OSError:time.sleep(.1)
            assert status==200 and s['ready'] is False
            assert admin.request('install',{'key':'wrong','name':'Admin','password':'long-admin-password'})[0]==403
            assert admin.request('install',{'key':'a'*64,'name':'Admin','password':'long-admin-password'})[0]==200
            assert admin.request('install',{'key':'a'*64,'name':'Admin','password':'long-admin-password'})[0]==409
            one=Client(url);two=Client(url)
            for client,name,mail in [(one,'One','one@example.test'),(two,'Two','two@example.test')]:
                client.request('status')
                status,s=client.request('register',{'name':name,'email':mail,'password':'long-client-password','consent':True})
                assert status==200 and s['user']['role']=='client'
            order={'title':'Test project','brief':'Answers and recommendation','answers':{'tasks':['maps']},'consent':True}
            assert one.request('create',order,csrf=False)[0]==403
            status,r=one.request('create',order);assert status==200;r_id=r['id'];suffix='&id='+str(r_id)
            assert two.request('order',extra=suffix)[0]==404
            assert two.request('message',{'body':'Intrusion'},suffix)[0]==404
            assert one.request('status-change',{'status':'done'},suffix)[0]==403
            assert one.request('message',{'body':'A question'},suffix)[0]==200
            boundary='ELIMORATESTBOUNDARY'
            body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="private.txt"\r\nContent-Type: text/plain\r\n\r\nPrivate client material\r\n--{boundary}--\r\n').encode()
            assert one.request('upload',extra=suffix,raw=(body,'multipart/form-data; boundary='+boundary))[0]==200
            status,r=one.request('order',extra=suffix);assert status==200 and len(r['order']['files'])==1
            fid=r['order']['files'][0]['id'];file_suffix=suffix+'&file='+str(fid)
            assert two.request('download',extra=file_suffix)[0]==404
            assert one.request('download',extra=file_suffix)==(200,b'Private client material')
            admin.request('status')
            assert admin.request('login',{'email':'admin@example.test','password':'long-admin-password'})[0]==200
            assert len(admin.request('orders')[1]['orders'])==1
            assert admin.request('order',extra=suffix)[0]==200
            assert admin.request('status-change',{'status':'work'},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['status']=='work'
            assert one.request('delete-file',{},file_suffix)[0]==200
            assert one.request('download',extra=file_suffix)[0]==404
            assert one.request('logout',{})[0]==200
            assert one.request('orders')[0]==401
            print('Passed: installation lock, login, registration, CSRF, client isolation, admin access, messages, upload/download/delete, logout')
        finally:server.terminate();server.wait(timeout=10)


check_allowlist()
if os.environ.get('CI_DB_PASSWORD'):integration()
else:print('Public-file allowlist passed; database integration runs in GitHub Actions.')
