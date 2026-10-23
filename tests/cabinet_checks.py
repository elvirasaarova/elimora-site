"""Integration checks against an isolated PHP server and a disposable CI MySQL database."""
import base64
import http.cookiejar
import json
import io
import zipfile
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
    for n in ['index.html','cabinet/api.php','cabinet/bootstrap.php','cabinet/workflow.php','cabinet/availability.php','cabinet/schema.sql','cabinet/.htaccess','assets/portrait.webp']:
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
        if r.headers.get_content_type() in ['application/octet-stream','text/plain'] or r.headers.get_content_type().startswith('image/'):return r.status,payload
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
                status,s=client.request('register',{'name':name,'email':mail,'password':'eight888','consent':True})
                assert status==200 and s['user']['role']=='client'
            assert two.request('register',{'name':'Short','email':'short@example.test','password':'seven77','consent':True})[0]==400
            order={'company':'Company One','title':'Test project','brief':'Answers and recommendation','answers':{'tasks':['maps']},'consent':True}
            assert one.request('create',order,csrf=False)[0]==403
            missing=dict(order);missing.pop('company')
            assert one.request('create',missing)[0]==400
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
            # A complete 30-photo batch, uploaded in chunks and categorized.
            png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7i0AAAAASUVORK5CYII=')
            def chunk(token,content,offset=0,client=one):
                body=(f'--{boundary}\r\nContent-Disposition: form-data; name="upload"\r\n\r\n{token}\r\n--{boundary}\r\nContent-Disposition: form-data; name="offset"\r\n\r\n{offset}\r\n--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="chunk.bin"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+f'\r\n--{boundary}--\r\n'.encode()
                return client.request('upload-chunk',extra=suffix,raw=(body,'multipart/form-data; boundary='+boundary))
            def begin(name,content,category='media',client=one,purpose='material',payment=None,asset='general'):
                code,result=client.request('upload-begin',{'name':name,'size':len(content),'category':category,'purpose':purpose,'payment':payment,'asset_type':asset},suffix)
                assert code==200,result
                return result['upload']
            assert one.request('upload-begin',{'name':'phone.heic','size':10,'category':'media'},suffix)[0]==400
            token=begin('photo-0.png',png)
            assert chunk(token,png,client=two)[0]==404
            assert chunk(token,png,offset=1)[0]==400
            assert chunk(token,png[:20])[1]['complete'] is False
            assert chunk(token,png[20:],20)[1]['complete'] is True
            assert chunk(token,png)[0]==404
            for i in range(1,30):
                token=begin(f'photo-{i}.png',png)
                assert chunk(token,png)[1]['complete'] is True
            files=one.request('files')[1]['files']
            assert len([f for f in files if f['category']=='media'])==30
            assert two.request('files')[1]['files']==[]
            photo=next(f for f in files if f['category']=='media')
            preview=suffix+'&file='+str(photo['id'])
            assert one.request('preview',extra=preview)==(200,png)
            assert two.request('preview',extra=preview)[0]==404
            assert one.request('preview',extra=file_suffix)[0]==415
            for ext,entry in [('docx','word/document.xml'),('xlsx','xl/workbook.xml')]:
                stream=io.BytesIO()
                with zipfile.ZipFile(stream,'w') as archive:
                    archive.writestr('[Content_Types].xml','<Types/>')
                    archive.writestr(entry,'<document/>')
                content=stream.getvalue();token=begin('price.'+ext,content,'documents')
                assert chunk(token,content)[1]['complete'] is True
            token=begin('logo.svg',b'<svg xmlns="http://www.w3.org/2000/svg"></svg>','brand')
            assert chunk(token,b'<svg xmlns="http://www.w3.org/2000/svg"></svg>')[1]['complete'] is True
            token=begin('fake.png',b'not an image')
            assert chunk(token,b'not an image')[0]==400
            assert one.request('upload-cancel',{'upload':token},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['company']=='Company One'
            admin.request('status')
            assert admin.request('login',{'email':'admin@example.test','password':'long-admin-password'})[0]==200
            assert len(admin.request('orders')[1]['orders'])==1
            assert admin.request('order',extra=suffix)[0]==200
            assert admin.request('status-change',{'status':'work'},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['status']=='work'
            started=one.request('order',extra=suffix)[1]['order']['work_started_at'];assert started
            assert admin.request('status-change',{'status':'materials'},suffix)[0]==200
            assert admin.request('status-change',{'status':'work'},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['work_started_at']==started
            # Paid briefing, versioned approval, invoice/QR and final delivery workflow.
            brief={'products':'Services','goals':'Explain our offer','audience':'Local customers','links':'https://example.test'}
            assert one.request('brief-submit',brief,suffix)[0]==403
            assert one.request('payment-create',{'title':'Deposit','amount_minor':500000},suffix)[0]==403
            assert admin.request('project-settings',{'kind':'maps','access_note':'Invite studio@example.test','planned_start':'2026-10-23','due_date':'2026-10-30'},suffix)[0]==200
            assert one.request('project-settings',{'kind':'maps','planned_start':'2026-10-23','due_date':'2026-10-30'},suffix)[0]==403
            assert admin.request('project-settings',{'kind':'maps','planned_start':'2026-10-30','due_date':'2026-10-23'},suffix)[0]==400
            assert one.request('order',extra=suffix)[1]['order']['due_date']=='2026-10-30'
            code,p=admin.request('payment-create',{'title':'Deposit','amount_minor':500000,'note':'Payment by invoice'},suffix)
            assert code==200,p
            payment=p['payment']
            pdf=b'%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n%%EOF'
            token=begin('invoice.pdf',pdf,'documents',admin,'invoice',payment)
            assert chunk(token,pdf,client=admin)[1]['complete'] is True
            token=begin('payment-qr.png',png,'media',admin,'qr',payment)
            assert chunk(token,png,client=admin)[1]['complete'] is True
            assert two.request('payment-update',{'payment':payment,'status':'reported'},suffix)[0]==404
            assert one.request('payment-update',{'payment':payment,'status':'paid'},suffix)[0]==403
            assert one.request('payment-update',{'payment':payment,'status':'reported'},suffix)[0]==200
            assert one.request('brief-submit',brief,suffix)[0]==403
            assert admin.request('payment-update',{'payment':payment,'status':'paid'},suffix)[0]==200
            assert one.request('brief-submit',brief,suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['detailed_brief']['answers']['goals']=='Explain our offer'
            assert two.request('payments')[1]['payments']==[]
            assert b'Explain our offer' in one.request('brief-download',extra=suffix)[1]
            assert two.request('brief-download',extra=suffix)[0]==404
            assert one.request('link-add',{'title':'Website','url':'https://example.test','asset_type':'multilink'},suffix)[0]==403
            assert admin.request('link-add',{'title':'Bad','url':'javascript:alert(1)','asset_type':'multilink'},suffix)[0]==400
            assert admin.request('link-add',{'title':'Multilink','url':'https://example.test','asset_type':'multilink'},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['links']==[]
            token=begin('preview-v1.png',png,'media',admin,'review',asset='stories')
            assert chunk(token,png,client=admin)[1]['complete'] is True
            assert not [f for f in one.request('order',extra=suffix)[1]['order']['files'] if f['purpose']=='review']
            assert one.request('review-publish',{'note':'Version 1'},suffix)[0]==403
            assert admin.request('review-publish',{'note':'Version 1'},suffix)[0]==200
            o=one.request('order',extra=suffix)[1]['order'];review=o['reviews'][0]['id']
            assert len(o['links'])==1 and o['links'][0]['review_id']
            review_file=next(f for f in o['files'] if f['purpose']=='review');assert review_file['asset_type']=='stories';review_suffix=suffix+'&file='+str(review_file['id'])
            assert one.request('download',extra=review_suffix)[0]==403
            assert one.request('preview',extra=review_suffix)==(200,png)
            assert one.request('review-answer',{'review':review,'decision':'changes','feedback':''},suffix)[0]==400
            assert one.request('review-answer',{'review':review,'decision':'changes','feedback':'Change title'},suffix)[0]==200
            assert one.request('review-answer',{'review':review,'decision':'approved'},suffix)[0]==409
            assert one.request('order',extra=suffix)[1]['order']['status']=='work'
            assert admin.request('delete-file',{},review_suffix)[0]==409
            token=begin('preview-v2.png',png,'media',admin,'review')
            assert chunk(token,png,client=admin)[1]['complete'] is True
            assert admin.request('review-publish',{'note':'Version 2'},suffix)[0]==200
            o=one.request('order',extra=suffix)[1]['order'];review=o['reviews'][0]['id']
            assert len(o['reviews'])==2
            assert two.request('review-answer',{'review':review,'decision':'approved'},suffix)[0]==404
            assert one.request('review-answer',{'review':review,'decision':'approved'},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['status']=='access'
            assert admin.request('release',{},suffix)[0]==400
            assert one.request('access-report',{},suffix)[0]==200
            assert admin.request('status-change',{'status':'delivery'},suffix)[0]==200
            token=begin('final.png',png,'media',admin,'final')
            assert chunk(token,png,client=admin)[1]['complete'] is True
            final=next(f for f in admin.request('order',extra=suffix)[1]['order']['files'] if f['purpose']=='final')
            final_suffix=suffix+'&file='+str(final['id'])
            assert one.request('download',extra=final_suffix)[0]==404
            code,p=admin.request('payment-create',{'title':'Balance','amount_minor':500000},suffix)
            assert code==200
            assert admin.request('release',{},suffix)[0]==400
            assert admin.request('payment-update',{'payment':p['payment'],'status':'paid'},suffix)[0]==200
            assert one.request('release',{},suffix)[0]==403
            assert admin.request('release',{},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['status']=='done'
            assert one.request('download',extra=final_suffix)==(200,png)
            assert one.request('download',extra=review_suffix)==(200,png)
            assert one.request('notifications')[1]['notifications']
            assert two.request('notifications')[1]['notifications']==[]
            assert one.request('notifications-read',{})[0]==200
            assert all(int(n['seen'])==1 for n in one.request('notifications')[1]['notifications'])
            # A repeat request stays in this same client's project history.
            other=dict(order);other['title']='Repeat design project';other['answers']={'tasks':['visual']}
            code,new=one.request('create',other);assert code==200
            assert len(one.request('orders')[1]['orders'])==2
            listed=admin.request('orders')[1]['orders'];assert [int(o['id']) for o in listed]==sorted(int(o['id']) for o in listed)
            suffix='&id='+str(new['id'])
            assert admin.request('link-add',{'title':'Creative preview','url':'https://example.test/preview','asset_type':'website'},suffix)[0]==200
            assert admin.request('review-publish',{'note':'Creative'},suffix)[0]==200
            review=one.request('order',extra=suffix)[1]['order']['reviews'][0]['id']
            assert one.request('review-answer',{'review':review,'decision':'approved'},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['status']=='delivery'
            assert admin.request('release',{},suffix)[0]==200
            assert one.request('delete-file',{},file_suffix)[0]==200
            assert one.request('download',extra=file_suffix)[0]==404
            # Attach a ready bank invoice: the payment is created only after file storage succeeds.
            code,third=one.request('create',other);assert code==200;suffix='&id='+str(third['id'])
            token=begin('bank-invoice.pdf',pdf,'documents',admin,'invoice')
            assert chunk(token,pdf,client=admin)[1]['complete'] is True
            o=one.request('order',extra=suffix)[1]['order'];assert len(o['payments'])==1
            invoice=o['payments'][0];assert int(invoice['amount_minor'])==0
            assert o['status']=='payment' and int(o['files'][0]['payment_id'])==int(invoice['id'])
            assert one.request('payment-update',{'payment':invoice['id'],'status':'reported'},suffix)[0]==200
            assert admin.request('payment-update',{'payment':invoice['id'],'status':'paid'},suffix)[0]==200
            assert one.request('brief-submit',brief,suffix)[0]==200
            assert admin.request('project-settings',{'kind':'design','planned_start':'2026-10-26','due_date':''},suffix)[0]==200
            assert one.request('order',extra=suffix)[1]['order']['due_date']=='2026-10-30'
            public_client=Client(url);code,forecast=public_client.request('availability')
            assert code==200 and forecast['capacity']==3 and forecast['duration_workdays']==5
            assert forecast['reserved_projects']==1
            assert set(forecast)=={'capacity','duration_workdays','active_projects','reserved_projects','start','finish','calendar','available_starts','timezone','weekdays'}
            assert len(forecast['calendar'])==40
            assert all(set(option)=={'start','finish'} for option in forecast['available_starts'])
            assert all(set(day)=={'date','occupied','free'} for day in forecast['calendar'])
            assert 'example.test' not in json.dumps(forecast)
            token=begin('bad-invoice.pdf',b'not a PDF','documents',admin,'invoice')
            assert chunk(token,b'not a PDF',client=admin)[0]==400
            assert admin.request('upload-cancel',{'upload':token},suffix)[0]==200
            assert len(one.request('order',extra=suffix)[1]['order']['payments'])==1
            assert one.request('logout',{})[0]==200
            assert one.request('orders')[0]==401
            print('Passed: ready bank invoice attachment without amount re-entry, failed uploads create no payments, public anonymous availability excludes unpaid/finished projects, five-workday planning; FIFO client queue, scheduling validation and role isolation, preserved work-start clock, grouped review asset types, private draft links and link-only approvals, brief export; payment-gated briefing, invoice and QR, versioned changes/approval, access handoff, guarded final release, private notifications, repeat project history; 8-character passwords, required company, 30-photo batch, chunk offsets and ownership, MIME validation, Word/Excel/brand files, private previews; installation lock, login, registration, CSRF, client isolation, admin access, messages, upload/download/delete, logout')
        finally:server.terminate();server.wait(timeout=10)


check_allowlist()
if os.environ.get('CI_DB_PASSWORD'):integration()
else:print('Public-file allowlist passed; database integration runs in GitHub Actions.')
