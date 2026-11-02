#!/usr/bin/env python3
"""Generate a route-complete OpenAPI index from Laravel's registered API routes."""
import json
import re
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[1]
routes = json.loads(subprocess.check_output(["php", "artisan", "route:list", "--json"], cwd=root))

def balanced(text, start, opening, closing):
    depth=0; quote=None; escaped=False
    for i in range(start,len(text)):
        ch=text[i]
        if quote:
            if escaped: escaped=False
            elif ch=='\\': escaped=True
            elif ch==quote: quote=None
            continue
        if ch in "'\"": quote=ch
        elif ch==opening: depth+=1
        elif ch==closing:
            depth-=1
            if depth==0:return text[start+1:i]
    return ""

def fields_from_controller(action, http_method):
    if '@' not in action:return {}
    cls,method=action.split('@',1);relative=cls.replace('App\\','').replace('\\','/')+'.php';source=root/'app'/relative
    if not source.exists():return {}
    php=source.read_text(errors='ignore');found=re.search(r'function\s+'+re.escape(method)+r'\s*\([^)]*\)[^{]*\{',php)
    if not found:return {}
    brace=php.find('{',found.start());body=balanced(php,brace,'{','}')
    fields={}
    calls=list(re.finditer(r'->validate\s*\(\s*\[',body))
    raw_arrays=[]
    for call in calls:
        # A method used by both GET and POST may validate in separate branches.
        preceding=body[:call.start()]
        branch=list(re.finditer(r"if\s*\(\s*\$\w+->isMethod\(\s*['\"]get['\"]\s*\)\s*\)",preceding))
        in_get_branch=False
        if branch:
            cond=branch[-1];opening=body.find('{',cond.end());semicolon=body.find(';',cond.end())
            if opening!=-1 and (semicolon==-1 or opening<semicolon):
                try: in_get_branch=call.start()<opening+1+len(balanced(body,opening,'{','}'))
                except Exception: in_get_branch=False
            elif semicolon!=-1:in_get_branch=call.start()<semicolon
        if (http_method=='get' and branch and not in_get_branch) or (http_method!='get' and in_get_branch):continue
        arr_start=body.find('[',call.start());raw_arrays.append(balanced(body,arr_start,'[',']'))
    if not raw_arrays:
        imports={}
        for grouped in re.finditer(r'use\s+([\\\w]+)\\\s*\{([^}]+)\}\s*;',php):
            prefix,members=grouped.groups()
            for member in members.split(','):
                member=member.strip()
                if member: imports[member.rsplit('\\',1)[-1]]=prefix+'\\'+member
        for imported in re.finditer(r'^use\s+([\\\w]+)(?:\s+as\s+(\w+))?\s*;',php,re.M):
            fqcn,alias=imported.groups();imports[alias or fqcn.rsplit('\\',1)[-1]]=fqcn
        for short in re.findall(r'\b([A-Z]\w*Request)\s+\$\w+',found.group(0)):
            fqcn=imports.get(short)
            if not fqcn:continue
            request_file=root/'app'/(fqcn.replace('App\\','').replace('\\','/')+'.php')
            if not request_file.exists():continue
            request_source=request_file.read_text(errors='ignore')
            rules_method=re.search(r'function\s+rules\s*\([^)]*\)\s*:\s*array\s*\{',request_source)
            if not rules_method:continue
            rules_body=balanced(request_source,request_source.find('{',rules_method.start()),'{','}')
            returned=re.search(r'return\s*\[',rules_body)
            if returned:
                raw_arrays.append(balanced(rules_body,rules_body.find('[',returned.start()),'[',']'))
    if not raw_arrays:return {}
    for raw in raw_arrays:
        segments=[];depth=0;quote=None;escaped=False;begin=0
        for i,ch in enumerate(raw):
            if quote:
                if escaped:escaped=False
                elif ch=='\\':escaped=True
                elif ch==quote:quote=None
                continue
            if ch in "'\"":quote=ch
            elif ch in '[({':depth+=1
            elif ch in '])}':depth-=1
            elif ch==',' and depth==0:segments.append(raw[begin:i]);begin=i+1
        if raw[begin:].strip():segments.append(raw[begin:])
        for seg in segments:
            m=re.match(r"\s*['\"]([^'\"]+)['\"]\s*=>\s*(.*)$",seg,re.S)
            if not m:continue
            name,rule=m.groups();rule=re.sub(r'\s+',' ',rule).strip();required=bool(re.search(r"(?:^|[|\s'\"])required(?:$|[|\s'\"])",rule)) and 'sometimes' not in rule
            typ='string'
            if re.search(r'\b(integer|numeric|decimal)\b',rule):typ='integer' if 'integer' in rule else 'number'
            elif re.search(r'\b(boolean|accepted|declined)\b',rule):typ='boolean'
            elif re.search(r'\b(array|list)\b',rule):typ='array'
            prop={'type':typ,'description':'Laravel validation: '+rule[:220]}
            enum=re.search(r"['\"]in:([^'\"]+)['\"]",rule)
            if enum:prop['enum']=enum.group(1).split(',')
            if typ=='string':
                mx=re.search(r'\bmax:(\d+)',rule)
                if mx:prop['maxLength']=int(mx.group(1))
                if 'email' in rule:prop['format']='email'
                if 'url' in rule:prop['format']='uri'
                if 'date' in rule:prop['format']='date-time' if 'date_format:Y-m-d\\TH:i:sP' in rule else 'date'
                if 'file' in rule or 'image' in rule:prop['format']='binary'
            if typ=='integer':
                mn=re.search(r'\bmin:(\d+)',rule);mx=re.search(r'\bmax:(\d+)',rule)
                if mn:prop['minimum']=int(mn.group(1))
                if mx:prop['maximum']=int(mx.group(1))
            if typ=='array':
                mn=re.search(r'\bmin:(\d+)',rule);mx=re.search(r'\bmax:(\d+)',rule)
                if mn:prop['minItems']=int(mn.group(1))
                if mx:prop['maxItems']=int(mx.group(1))
            fields[name]={'schema':prop,'required':required}
    nested={}
    for name,field in list(fields.items()):
        if '.*.' in name:
            parent,child=name.split('.*.',1)
            if parent in fields and fields[parent]['schema'].get('type')=='array':
                item=fields[parent]['schema'].setdefault('items',{'type':'object','properties':{}})
                item.setdefault('properties',{})[child]=field['schema']
                if field['required']:item.setdefault('required',[]).append(child)
                nested[name]=True
    for name in nested:del fields[name]
    dotted={}
    for name,field in list(fields.items()):
        if '.' in name and '*' not in name:
            parent,child=name.split('.',1)
            if parent in fields:
                target=fields[parent]['schema'];target['type']='object';target.pop('items',None)
                target.setdefault('properties',{})[child]=field['schema']
                if field['required']:target.setdefault('required',[]).append(child)
                dotted[name]=True
    for name in dotted:del fields[name]
    for name,field in list(fields.items()):
        if name.endswith('.*'):
            parent=name[:-2]
            if parent in fields:
                fields[parent]['schema']['items']=field['schema']
            del fields[name]
    return fields
paths = {}
for route in routes:
    uri = route["uri"]
    if not uri.startswith("api/") or "*" in uri:
        continue
    path = "/" + uri[4:]
    middleware = " ".join(route.get("middleware") or [])
    authenticated = "sanctum" in middleware.lower()
    for method in route["method"].split("|"):
        method = method.lower()
        if method not in {"get", "post", "put", "patch", "delete"}:
            continue
        params = [{"name": p, "in": "path", "required": True, "schema": {"type": "string"}}
                  for p in re.findall(r"\{([^}]+)\}", path)]
        action = route.get("action", "API")
        fields=fields_from_controller(action,method)
        op = {
            "operationId": re.sub(r"[^A-Za-z0-9]+", "_", method.upper() + "_" + path).strip("_"),
            "summary": action.rsplit("@", 1)[-1] if "@" in action else action,
            "description": "Laravel controller: " + action + ". Request properties are derived from controller validation or FormRequest rules where available; role permissions are derived from route middleware.",
            "tags": [path.strip("/").split("/")[0] or "API"],
            "parameters": params,
            "responses": {
                "200": {"description": "Successful response"}, "201": {"description": "Created"},
                "401": {"description": "Unauthenticated"}, "403": {"description": "Forbidden"},
                "404": {"description": "Not found"}, "409": {"description": "Conflict or invalid transition"}, "429":{"description":"Rate limit exceeded"},
                "422": {"description": "Validation error: JSON message and field-keyed errors"},
            },
        }
        op['responses']['422']['content']={'application/json':{'schema':{'$ref':'#/components/schemas/ValidationError'},'example':{'message':'The given data was invalid.','errors':{'field':['The field is required.']}}}}
        for status,message in [('401','Unauthenticated'),('403','Forbidden'),('404','Resource not found'),('409','State conflict')]:
            op['responses'][status]['content']={'application/json':{'schema':{'$ref':'#/components/schemas/ApiError'},'example':{'message':message}}}
        op['responses']['429']['content']={'application/json':{'schema':{'$ref':'#/components/schemas/ApiError'},'example':{'message':'Too many requests.'}}}
        if method=='delete':
            op['responses']['204']={'description':'Deleted; empty response body'}
        if path.endswith('.xlsx'):op['responses']['200']['content']={'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':{'schema':{'type':'string','format':'binary'}}}
        if path in ['/content-media/{media}','/admin/content-media/{media}/preview'] and method=='get':op['responses']['200']['content']={'image/*':{'schema':{'type':'string','format':'binary'}}}
        if path in ['/admin/booking/clinical/attachments/{attachment}','/counselor/clinical/attachments/{attachment}'] and method=='get':op['responses']['200']['content']={'application/octet-stream':{'schema':{'type':'string','format':'binary'}}}
        if authenticated:
            op["security"] = [{"sanctum": []}]
            if '/admin/' in path or path.startswith('/admin/'):op['x-roles']=['admin','reception','finance','clinical','content','operations']
            elif '/counselor/' in path or path.startswith('/counselor/'):op['x-roles']=['counselor']
            elif '/client/' in path or path.startswith('/client/') or path.startswith('/booking/my-reservations'):op['x-roles']=['client']
            permission=re.search(r'EnsureAdmin:([a-z.]+)',middleware)
            if permission:op['x-required-permissions']=[permission.group(1)]
        if method=='get':
            parameters=op['parameters'];existing={p['name'] for p in parameters}
            for key,value in fields.items():
                if key not in existing:parameters.append({'name':key,'in':'query','required':value['required'],'schema':value['schema']})
            op['parameters']=parameters
        if method in {"post", "put", "patch"}:
            # Laravel validates known fields but generally ignores unknown request keys;
            # do not describe those requests as rejecting additional properties.
            schema={"type":"object","properties":{k:v['schema'] for k,v in fields.items()},"additionalProperties":True}
            required=[k for k,v in fields.items() if v['required']]
            if required:schema['required']=required
            media='multipart/form-data' if any(value['schema'].get('format')=='binary' for value in fields.values()) else 'application/json'
            op["requestBody"] = {"required": bool(required), "content": {media: {"schema": schema}}}
            if fields:
                example={}
                for k,v in fields.items():
                    prop=v['schema']
                    if 'enum' in prop:example[k]=prop['enum'][0]
                    elif prop['type']=='integer' or prop['type']=='number':example[k]=1
                    elif prop['type']=='boolean':example[k]=True if 'accepted' in prop.get('description','') else False
                    elif prop['type']=='object':example[k]={key:(values.get('enum') or ['example'])[0] if 'enum' in values else (True if values.get('type')=='boolean' else 1 if values.get('type') in ['integer','number'] else 'example') for key,values in prop.get('properties',{}).items() if key in prop.get('required',[])}
                    elif prop['type']=='array':
                        items=prop.get('items',{})
                        if items.get('type')=='object':example[k]=[{key: (values.get('enum') or ['example'])[0] if 'enum' in values else (False if values.get('type')=='boolean' else 1 if values.get('type') in ['integer','number'] else 'example') for key,values in items.get('properties',{}).items() if key in items.get('required',[])}] if items.get('required') else []
                        elif items.get('enum') and 'minItems' in prop:example[k]=[items['enum'][0]]
                        elif 'minItems' in prop:example[k]=['example']
                        else:example[k]=[]
                    elif 'email' in k:example[k]='client@example.test'
                    elif 'password' in k:example[k]='ChangeMe-12345'
                    elif 'phone' in k:example[k]='+989121234567'
                    elif 'starts_at' in k or 'ends_at' in k or 'created_at' in k:example[k]='2026-10-20T10:00:00+03:30'
                    elif 'date' in k:example[k]='2026-10-20'
                    else:example[k]='example'
                op['requestBody']['content'][media]['examples']={'validatedRequest':{'summary':'Example payload based on Laravel validation rules','value':example}}
        op['responses']['200']['content']={'application/json':{'schema':{'type':'object','properties':{'data':{'oneOf':[{'type':'object','additionalProperties':True},{'type':'array','items':{'type':'object','additionalProperties':True}}]},'message':{'type':'string'},'errors':{'type':'object','additionalProperties':{'type':'array','items':{'type':'string'}}}}}}}
        # Most create handlers return the same JSON envelope at 201; mirror its schema.
        if method in {'post','put','patch'}:
            op['responses']['201']['content']=op['responses']['200']['content']
        data_ref=None
        if path=='/admin/booking/payments':data_ref='#/components/schemas/PaginatedPaymentsEnvelope'
        elif 'fake-bank/payments' in path or '/booking/my-reservations/{booking}/payments' in path or path.endswith('/booking/payments'):data_ref='#/components/schemas/PaymentAttempt'
        elif method=='get' and path.endswith('/sms-reminders/rules'):data_ref='#/components/schemas/PaginatedSmsReminderRules'
        elif 'sms-reminders/rules' in path:data_ref='#/components/schemas/SmsReminderRule'
        elif 'sms-reminders/scheduled' in path or 'sms-reminders/history' in path:data_ref='#/components/schemas/PaginatedSmsReminders'
        elif 'sms-reminders/attempts' in path:data_ref='#/components/schemas/PaginatedSmsAttempts'
        elif 'notification-preferences' in path:data_ref='#/components/schemas/NotificationPreference'
        elif path.endswith('/clinical-record'):data_ref='#/components/schemas/ClinicalRecord'
        elif path.endswith('/intake-answers'):data_ref='#/components/schemas/PaginatedClinicalAnswers'
        elif path=='/client/consents':data_ref='#/components/schemas/ClinicalConsent'
        elif path.endswith('/consents'):data_ref='#/components/schemas/ClinicalConsentList'
        elif path.endswith('/follow-ups'):data_ref='#/components/schemas/PaginatedFollowUps' if method=='get' else '#/components/schemas/FollowUpTask'
        elif path.endswith('/attachments'):data_ref='#/components/schemas/PaginatedAttachments' if method=='get' else '#/components/schemas/ClinicalAttachment'
        elif path.endswith('/clinical/forms'):data_ref='#/components/schemas/IntakeFormList' if method=='get' else '#/components/schemas/IntakeForm'
        elif path.endswith('/versions/{version}') and 'session-notes' in path:data_ref='#/components/schemas/ClinicalNoteVersion'
        elif path.endswith('/versions') and 'session-notes' in path:data_ref='#/components/schemas/ClinicalNoteVersionList'
        elif 'session-notes' in path or path.endswith('/appointments/{booking}/notes'):data_ref='#/components/schemas/ClinicalNoteList' if method=='get' else '#/components/schemas/ClinicalNote'
        elif path.endswith('/intake-forms/{form}/answers'):data_ref='#/components/schemas/IntakeSubmission'
        elif path.endswith('/intake-forms/{form}'):data_ref='#/components/schemas/IntakeForm'
        elif path=='/admin/content-media':data_ref='#/components/schemas/PaginatedMediaEnvelope'
        elif path.endswith('/gallery/upload'):data_ref='#/components/schemas/MediaUpload'
        elif path.endswith('/availability') and '/rooms/' in path:data_ref='#/components/schemas/RoomAvailability'
        elif path.endswith('/working-hours') and '/rooms/' in path:data_ref='#/components/schemas/WorkingHours'
        elif path.endswith('/booking/payments'):data_ref='#/components/schemas/PaginatedPaymentsEnvelope'
        elif path.endswith('/booking/refunds'):data_ref='#/components/schemas/PaginatedRefundsEnvelope'
        elif path.endswith('/booking/settlements'):data_ref='#/components/schemas/PaginatedSettlementsEnvelope'
        elif path=='/admin/booking/services':data_ref='#/components/schemas/PaginatedServicesEnvelope' if method=='get' else '#/components/schemas/BookingService'
        elif path=='/admin/booking/reservations' and method=='get':data_ref='#/components/schemas/PaginatedBookingsEnvelope'
        elif path=='/booking/services':data_ref='#/components/schemas/BookingServiceList'
        elif '/booking/rooms' in path or 'room-unavailability' in path:data_ref='#/components/schemas/Room'
        elif '/booking/reservations' in path or '/booking/my-reservations' in path:data_ref='#/components/schemas/Booking'
        elif '/content/' in path:data_ref='#/components/schemas/CmsEntry'
        list_paths=['/admin/booking/payments','/admin/booking/refunds','/admin/booking/settlements','/admin/settings/sms-reminders/rules','/admin/settings/sms-reminders/history','/admin/settings/sms-reminders/scheduled','/admin/booking/reservations','/booking/my-reservations','/admin/booking/services','/admin/content-media']
        if method=='get' and path in list_paths and data_ref is None:data_ref='#/components/schemas/PaginatedResponse'
        if data_ref and 'content' in op['responses']['200']:
            envelope=op['responses']['200']['content']['application/json']['schema'];envelope['properties']['data']={'$ref':data_ref}
            paginator={'current_page':1,'data':[],'first_page_url':None,'from':None,'last_page':1,'last_page_url':None,'links':[],'next_page_url':None,'path':'/api'+path,'per_page':30,'prev_page_url':None,'to':None,'total':0}
            example={'data':paginator} if method=='get' and path in list_paths else {'data':{'id':1,'status':'confirmed'}}
            op['responses']['200']['content']['application/json']['example']=example
        # Laravel JsonResource collections and paginator returns are root-level documents,
        # while the DB/query endpoints above use an explicit {data: ...} envelope.
        root_lists={
            '/counselors':'PaginatedCounselors','/admin/counselors':'PaginatedCounselors',
            '/specialties':'PaginatedSpecialties','/admin/specialties':'PaginatedSpecialties',
            '/content/{type}':'PaginatedCmsEntries','/admin/content/{type}':'PaginatedCmsEntries',
            '/booking/my-reservations':'PaginatedBookings',
        }
        if method=='get' and path in root_lists:
            ref='#/components/schemas/'+root_lists[path]
            op['responses']['200']['content']={'application/json':{'schema':{'$ref':ref},'example':{'data':[],'links':{'first':None,'last':None,'prev':None,'next':None},'meta':{'current_page':1,'per_page':20,'total':0}}}}
        elif method=='get' and path=='/counselors/{counselor}':
            op['responses']['200']['content']={'application/json':{'schema':{'$ref':'#/components/schemas/CounselorResourceEnvelope'},'example':{'data':{'id':4,'name':'Counselor','slug':'counselor','is_active':True,'is_public':True,'specialties':[]}}}}
        elif method=='get' and path=='/content/{type}/{slug}':
            op['responses']['200']['content']={'application/json':{'schema':{'$ref':'#/components/schemas/CmsEntryEnvelope'},'example':{'data':{'id':7,'type':'pages','title':'About','slug':'about','status':'published','is_active':True}}}}
        elif method=='get' and path=='/booking/services':
            op['responses']['200']['content']={'application/json':{'schema':{'type':'object','properties':{'data':{'$ref':'#/components/schemas/BookingServiceList'}}},'example':{'data':[]}}}
        if path in ['/admin/login','/client/login','/client/register','/counselor/login']:
            op['responses']['200']['content']={'application/json':{'schema':{'type':'object','properties':{'token':{'type':'string'},'user':{'type':'object'},'data':{'type':'object'}}},'example':{'token':'1|sanctum-token','data':{'id':42,'name':'Client'}}}}
        if path=='/booking/availability':
            op['responses']['200']['content']={'application/json':{'schema':{'type':'object','properties':{'data':{'type':'array','items':{'$ref':'#/components/schemas/AvailableSlot'}},'timezone':{'type':'string'}}},'example':{'timezone':'Asia/Tehran','data':[{'starts_at':'2026-10-20T10:00:00+03:30','ends_at':'2026-10-20T11:00:00+03:30','available':True}]}}}
        if path.endswith('/fake-bank/payments/{payment}/result'):
            media=op.get('requestBody',{}).get('content',{}).get('application/json')
            if media:media['examples']={'success':{'summary':'Simulate success in local/testing only','value':{'token':'opaque-issued-token','result':'success'}},'failure':{'summary':'Simulate failure in local/testing only','value':{'token':'opaque-issued-token','result':'failure'}}}
        if method in {'post','put','patch'} and 'content' in op['responses']['200']:
            op['responses']['201']['content']=op['responses']['200']['content']
        if method=='delete':op['responses'].pop('200',None)
        paths.setdefault(path, {})[method] = op

auth_schemas = {
    "AuthFlowError": {"type":"object","required":["success","code","message"],"properties":{"success":{"type":"boolean","enum":[False]},"code":{"type":"string"},"message":{"type":"string"},"retry_after_seconds":{"type":"integer"},"missing_profile_fields":{"type":"array","items":{"type":"string"}}}},
    "OtpRequest": {"type":"object","required":["mobile","recaptcha_token","recaptcha_action"],"properties":{"mobile":{"type":"string","example":"09123456789"},"recaptcha_token":{"type":"string"},"recaptcha_action":{"type":"string","enum":["otp_request","otp_resend"]}}},
    "OtpAccepted": {"type":"object","required":["success","data"],"properties":{"success":{"type":"boolean","enum":[True]},"data":{"type":"object","required":["challenge_id","expires_in","resend_after","code_length"],"properties":{"challenge_id":{"type":"string","format":"uuid"},"expires_in":{"type":"integer","example":300},"resend_after":{"type":"integer","example":60},"code_length":{"type":"integer","example":6},"accepted_requests_remaining":{"type":"integer"}}}}},
    "OtpVerify": {"type":"object","required":["mobile","challenge_id","code"],"properties":{"mobile":{"type":"string"},"challenge_id":{"type":"string","format":"uuid"},"code":{"type":"string","minLength":6,"maxLength":6}}},
    "ProfileStatus": {"type":"object","properties":{"profile_complete":{"type":"boolean"},"missing_profile_fields":{"type":"array","items":{"type":"string","enum":["first_name","last_name","mobile"]}},"mobile_verified":{"type":"boolean"},"has_password":{"type":"boolean"}}},
    "AuthenticatedSession": {"allOf":[{"$ref":"#/components/schemas/ProfileStatus"},{"type":"object","required":["token","data"],"properties":{"token":{"type":"string","description":"Sanctum PAT returned by existing login architecture"},"data":{"type":"object","additionalProperties":True}}}]},
    "OtpLoginSuccess": {"allOf":[{"$ref":"#/components/schemas/AuthenticatedSession"},{"type":"object","required":["success"],"properties":{"success":{"type":"boolean","enum":[True]}}}]},
    "ProfileIncomplete": {"type":"object","required":["success","code","message","missing_profile_fields"],"properties":{"success":{"type":"boolean","enum":[False]},"code":{"type":"string","enum":["PROFILE_INCOMPLETE"]},"message":{"type":"string"},"missing_profile_fields":{"type":"array","items":{"type":"string"}}}},
}

def json_body(schema, required=True, example=None):
    result={"required":required,"content":{"application/json":{"schema":schema}}}
    if example is not None: result["content"]["application/json"]["example"]=example
    return result

for path in ["/auth/otp/request", "/auth/otp/resend"]:
    op=paths.get(path,{}).get("post")
    if op:
        op["requestBody"]=json_body({"$ref":"#/components/schemas/OtpRequest"},True,{"mobile":"09123456789","recaptcha_token":"v3-token","recaptcha_action":"otp_request" if path.endswith("request") else "otp_resend"})
        op["responses"]["200"]={"description":"SMS accepted; this does not authenticate the user","content":{"application/json":{"schema":{"$ref":"#/components/schemas/OtpAccepted"}}}}
        op["responses"]["429"]["content"]={"application/json":{"schema":{"$ref":"#/components/schemas/AuthFlowError"}}}
        op["responses"]["503"]={"description":"CAPTCHA or SMS provider unavailable","content":{"application/json":{"schema":{"$ref":"#/components/schemas/AuthFlowError"}}}}
legacy=paths.get("/client/register",{}).get("post")
if legacy:
    legacy["deprecated"]=True
    legacy["summary"]="Deprecated: public account creation requires OTP"
    legacy["description"]="Compatibility route. Always returns 410 OTP_REGISTRATION_REQUIRED and never creates an account. New client accounts are created only after successful POST /auth/otp/verify. Privileged account management remains under authenticated admin routes."
    legacy.pop("requestBody",None)
    legacy["responses"]["410"]={"description":"Use verified mobile OTP to create a client account","content":{"application/json":{"schema":{"$ref":"#/components/schemas/AuthFlowError"},"example":{"success":False,"code":"OTP_REGISTRATION_REQUIRED","message":"برای ساخت حساب، شماره موبایل خود را با کد پیامکی تأیید کنید."}}}}
login=paths.get("/client/login",{}).get("post")
if login:
    login["description"]="Existing client password login. The historical `email` field accepts either email or an Iranian mobile number. New accounts are never created here. Unknown mobile and wrong password use the same generic credential error."
    login["responses"]["200"]={"description":"Authenticated Sanctum token and profile capabilities","content":{"application/json":{"schema":{"$ref":"#/components/schemas/AuthenticatedSession"}}}}
    login["responses"]["422"]["content"]={"application/json":{"schema":{"$ref":"#/components/schemas/AuthFlowError"},"example":{"message":"Invalid credentials"}}}
me=paths.get("/auth/me",{}).get("get")
if me:
    me["responses"]["200"]={"description":"Authoritative current profile and authentication capabilities","content":{"application/json":{"schema":{"type":"object","required":["data"],"properties":{"data":{"allOf":[{"type":"object","properties":{"id":{"type":"integer"},"name":{"type":"string"},"first_name":{"type":"string","nullable":True},"last_name":{"type":"string","nullable":True},"phone":{"type":"string","nullable":True},"phone_verified_at":{"type":"string","format":"date-time","nullable":True}}},{"$ref":"#/components/schemas/ProfileStatus"}]}}}}}}
password_set=paths.get("/auth/password/set",{}).get("post")
if password_set:
    password_set["responses"]["409"]={"description":"Password already configured; use password-change flow","content":{"application/json":{"schema":{"$ref":"#/components/schemas/AuthFlowError"},"example":{"success":False,"code":"PASSWORD_ALREADY_CONFIGURED","message":"این حساب از قبل رمز عبور دارد."}}}}
for path in ["/auth/otp/verify", "/auth/mobile/change/verify", "/auth/otp/step-up/verify"]:
    op=paths.get(path,{}).get("post")
    if op and path=="/auth/otp/verify":
        op["requestBody"]=json_body({"$ref":"#/components/schemas/OtpVerify"},True,{"mobile":"09123456789","challenge_id":"00000000-0000-4000-8000-000000000000","code":"123456"})
        op["responses"]["200"]={"description":"Authenticated Sanctum token and profile capabilities","content":{"application/json":{"schema":{"$ref":"#/components/schemas/OtpLoginSuccess"}}}}
    if op: op["responses"]["422"]["content"]={"application/json":{"schema":{"$ref":"#/components/schemas/AuthFlowError"}}}
if "/booking/reservations" in paths:
    for method,op in paths["/booking/reservations"].items():
        if method in {"post","put","patch"}:
            op["responses"]["422"]["content"]={"application/json":{"schema":{"$ref":"#/components/schemas/ProfileIncomplete"},"example":{"success":False,"code":"PROFILE_INCOMPLETE","message":"برای رزرو نوبت، اطلاعات حساب کاربری خود را تکمیل کنید.","missing_profile_fields":["first_name","last_name"]}}}

spec = {
    "openapi": "3.0.3",
    "info": {"title": "Counseling Center API", "version": "1.0.0", "description": "Generated from registered Laravel API routes and Laravel validation rules. Core booking, clinical, finance, CMS, counselor, reminder and export responses use named schemas; some legacy responses remain generic. Consult docs/BACKEND-API-CONTRACTS.md for known response-shape limitations."},
    "servers": [
        {"url": "http://localhost:8010/api", "description": "Main local API"},
        {"url": "http://localhost:8011/api", "description": "Isolated test API"},
    ],
    "components": {"securitySchemes": {"sanctum": {"type": "http", "scheme": "bearer", "bearerFormat": "Sanctum personal access token"}},"schemas":{
        **auth_schemas,
        "ApiError":{"type":"object","required":["message"],"properties":{"message":{"type":"string"},"code":{"type":"string"},"errors":{"type":"object","additionalProperties":{"type":"array","items":{"type":"string"}}}}},
        "ValidationError":{"type":"object","required":["message","errors"],"properties":{"message":{"type":"string","example":"The given data was invalid."},"errors":{"type":"object","additionalProperties":{"type":"array","items":{"type":"string"}}}}},
        "PaginationLinks":{"type":"object","properties":{"first":{"type":"string","format":"uri","nullable":True},"last":{"type":"string","format":"uri","nullable":True},"prev":{"type":"string","format":"uri","nullable":True},"next":{"type":"string","format":"uri","nullable":True}}},
        "PaginationMeta":{"type":"object","properties":{"current_page":{"type":"integer"},"from":{"type":"integer","nullable":True},"last_page":{"type":"integer"},"links":{"type":"array","items":{"type":"object","additionalProperties":True}},"path":{"type":"string"},"per_page":{"type":"integer"},"to":{"type":"integer","nullable":True},"total":{"type":"integer"}}},
        "AvailableSlot":{"type":"object","required":["starts_at","ends_at","available"],"properties":{"starts_at":{"type":"string","format":"date-time"},"ends_at":{"type":"string","format":"date-time"},"available":{"type":"boolean"}}},
        "PaginatedResponse":{"type":"object","properties":{"current_page":{"type":"integer"},"data":{"type":"array","items":{"type":"object","additionalProperties":True}},"first_page_url":{"type":"string","nullable":True},"from":{"type":"integer","nullable":True},"last_page":{"type":"integer"},"last_page_url":{"type":"string","nullable":True},"links":{"type":"array","items":{"type":"object","additionalProperties":True}},"next_page_url":{"type":"string","nullable":True},"path":{"type":"string"},"per_page":{"type":"integer"},"prev_page_url":{"type":"string","nullable":True},"to":{"type":"integer","nullable":True},"total":{"type":"integer"}}},
        "ResourceCollection":{"type":"object","properties":{"data":{"type":"array","items":{"type":"object","additionalProperties":True}},"links":{"$ref":"#/components/schemas/PaginationLinks"},"meta":{"$ref":"#/components/schemas/PaginationMeta"}}},
        "PaginatedCounselors":{"allOf":[{"$ref":"#/components/schemas/ResourceCollection"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/Counselor"}}}},
        "PaginatedSpecialties":{"allOf":[{"$ref":"#/components/schemas/ResourceCollection"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/Specialty"}}}},
        "PaginatedCmsEntries":{"allOf":[{"$ref":"#/components/schemas/ResourceCollection"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/CmsEntry"}}}},
        "PaginatedBookings":{"$ref":"#/components/schemas/PaginatedResponse"},
        "PaginatedPaymentsEnvelope":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/PaymentRecord"}}}},
        "PaginatedBookingsEnvelope":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/Booking"}}}},
        "PaginatedRefundsEnvelope":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/Refund"}}}},
        "PaginatedSettlementsEnvelope":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/Settlement"}}}},
        "PaginatedServicesEnvelope":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/BookingService"}}}},
        "PaginatedMediaEnvelope":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/MediaItem"}}}},
        "MediaItem":{"type":"object","properties":{"id":{"type":"integer"},"uploaded_by":{"type":"integer","nullable":True},"disk":{"type":"string"},"path":{"type":"string"},"original_name":{"type":"string"},"mime_type":{"type":"string"},"size_bytes":{"type":"integer"},"created_at":{"type":"string","format":"date-time"}}},
        "MediaUpload":{"type":"object","required":["id","path","url","mime_type","size"],"properties":{"id":{"type":"integer"},"path":{"type":"string"},"url":{"type":"string","format":"uri-reference"},"mime_type":{"type":"string"},"size":{"type":"integer"}}},
        "BookingServiceList":{"type":"array","items":{"$ref":"#/components/schemas/BookingService"}},
        "CounselorResourceEnvelope":{"type":"object","properties":{"data":{"$ref":"#/components/schemas/Counselor"}}},
        "CmsEntryEnvelope":{"type":"object","properties":{"data":{"$ref":"#/components/schemas/CmsEntry"}}},
        "PaginatedSmsReminders":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/SmsReminder"}}}},
        "PaginatedSmsAttempts":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/SmsDeliveryAttempt"}}}},
        "PaginatedSmsReminderRules":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/SmsReminderRule"}}}},
        "Booking":{"type":"object","properties":{"id":{"type":"integer"},"user_id":{"type":"integer"},"counselor_id":{"type":"integer"},"booking_service_id":{"type":"integer"},"starts_at":{"type":"string","format":"date-time"},"ends_at":{"type":"string","format":"date-time"},"status":{"type":"string","enum":["held","confirmed","checked_in","in_progress","completed","cancelled","no_show","expired"]},"mode":{"type":"string","enum":["online","in_person"]},"session_mode":{"type":"string","enum":["in_person","meet","voice_call"]},"total_irr":{"type":"integer"},"paid_irr":{"type":"integer"},"room_id":{"type":"integer","nullable":True}}},
        "PaymentAttempt":{"type":"object","properties":{"payment_id":{"type":"integer"},"booking_id":{"type":"integer"},"provider":{"type":"string","enum":["fake_bank","zarinpal","snappay","in_person"]},"status":{"type":"string","enum":["pending","verified","failed"]},"amount_irr":{"type":"integer"},"url":{"type":"string","format":"uri"},"checkout_token":{"type":"string"},"expires_at":{"type":"string","format":"date-time"}}},
        "PaymentRecord":{"type":"object","properties":{"id":{"type":"integer"},"booking_id":{"type":"integer"},"provider":{"type":"string","enum":["fake_bank","zarinpal","snappay","in_person"]},"status":{"type":"string"},"amount_irr":{"type":"integer"},"payment_method":{"type":"string","nullable":True},"provider_reference":{"type":"string","nullable":True},"verified_at":{"type":"string","format":"date-time","nullable":True},"recorded_by":{"type":"integer","nullable":True},"created_at":{"type":"string","format":"date-time"}}},
        "Refund":{"type":"object","properties":{"id":{"type":"integer"},"booking_id":{"type":"integer"},"requested_by":{"type":"integer"},"approved_by":{"type":"integer","nullable":True},"base_amount_irr":{"type":"integer"},"penalty_irr":{"type":"integer"},"refund_amount_irr":{"type":"integer"},"status":{"type":"string","enum":["requested","approved","rejected","processing","refunded","failed"]},"reason":{"type":"string","nullable":True},"cancels_booking":{"type":"boolean"},"created_at":{"type":"string","format":"date-time"},"updated_at":{"type":"string","format":"date-time"}}},
        "Settlement":{"type":"object","properties":{"id":{"type":"integer"},"counselor_id":{"type":"integer"},"amount_irr":{"type":"integer"},"reference":{"type":"string"},"note":{"type":"string","nullable":True},"recorded_by":{"type":"integer","nullable":True},"created_at":{"type":"string","format":"date-time"}}},
        "BookingService":{"type":"object","properties":{"id":{"type":"integer"},"title":{"type":"string"},"duration_minutes":{"type":"integer"},"price_irr":{"type":"integer"},"deposit_irr":{"type":"integer"},"is_active":{"type":"boolean"},"created_at":{"type":"string","format":"date-time"},"updated_at":{"type":"string","format":"date-time"}}},
        "SmsReminderRule":{"type":"object","required":["id","name","timing_type","recipients","template","is_active"],"properties":{"id":{"type":"integer"},"name":{"type":"string","maxLength":120},"timing_type":{"type":"string","enum":["relative","fixed_local"]},"minutes_before":{"type":"integer","minimum":0,"maximum":43200,"nullable":True},"local_time":{"type":"string","format":"time","nullable":True},"recipients":{"type":"array","items":{"type":"string","enum":["client","counselor","reception"]}},"modes":{"type":"array","items":{"type":"string","enum":["in_person","meet","voice_call"]},"nullable":True},"template":{"type":"string","maxLength":1000},"quiet_start":{"type":"string","format":"time","nullable":True},"quiet_end":{"type":"string","format":"time","nullable":True},"max_attempts":{"type":"integer","minimum":1,"maximum":8},"retry_delay_seconds":{"type":"integer","minimum":30,"maximum":86400},"is_active":{"type":"boolean"},"created_at":{"type":"string","format":"date-time"},"updated_at":{"type":"string","format":"date-time"}}},
        "SmsReminder":{"type":"object","properties":{"id":{"type":"integer"},"booking_id":{"type":"integer"},"rule_id":{"type":"integer"},"user_id":{"type":"integer"},"recipient_type":{"type":"string","enum":["client","counselor","reception"]},"appointment_starts_at":{"type":"string","format":"date-time"},"scheduled_at":{"type":"string","format":"date-time"},"available_at":{"type":"string","format":"date-time","nullable":True},"status":{"type":"string","enum":["pending","queued","sending","simulated","sent","failed","cancelled","skipped","not_configured"]},"attempts":{"type":"integer"},"retry_attempts":{"type":"integer"},"provider":{"type":"string","nullable":True},"provider_reference":{"type":"string","nullable":True},"last_error":{"type":"string","nullable":True},"sent_at":{"type":"string","format":"date-time","nullable":True}}},
        "SmsDeliveryAttempt":{"type":"object","properties":{"id":{"type":"integer"},"reminder_id":{"type":"integer"},"attempt_number":{"type":"integer"},"provider":{"type":"string","nullable":True},"status":{"type":"string"},"provider_reference":{"type":"string","nullable":True},"error_code":{"type":"string","nullable":True},"attempted_at":{"type":"string","format":"date-time"},"completed_at":{"type":"string","format":"date-time","nullable":True}}},
        "NotificationPreference":{"type":"object","properties":{"user_id":{"type":"integer"},"sms_enabled":{"type":"boolean"},"sms_consent_at":{"type":"string","format":"date-time","nullable":True}}},
        "RoomAvailability":{"type":"object","properties":{"room_id":{"type":"integer"},"available":{"type":"boolean"},"conflict":{"type":"boolean"},"maintenance":{"type":"boolean"},"center_holiday":{"type":"boolean"},"outside_center_hours":{"type":"boolean"},"outside_working_hours":{"type":"boolean"},"capacity":{"type":"integer"},"over_capacity":{"type":"boolean"}}},
        "WorkingHours":{"type":"array","items":{"type":"object","properties":{"weekday":{"type":"integer","minimum":0,"maximum":6},"opens_at":{"type":"string","format":"time"},"closes_at":{"type":"string","format":"time"}}}},
        "ClinicalNote":{"type":"object","properties":{"id":{"type":"integer"},"booking_id":{"type":"integer"},"counselor_id":{"type":"integer"},"author_id":{"type":"integer"},"body":{"type":"string"},"outcomes":{"type":"array","items":{"type":"string"},"nullable":True},"status":{"type":"string","enum":["draft","finalized"]},"finalized_at":{"type":"string","format":"date-time","nullable":True},"follow_up_recommendations":{"type":"string","nullable":True},"treatment_progress":{"type":"string","nullable":True},"created_at":{"type":"string","format":"date-time"},"updated_at":{"type":"string","format":"date-time"}}},
        "Room":{"type":"object","properties":{"id":{"type":"integer"},"name":{"type":"string"},"capacity":{"type":"integer"},"is_active":{"type":"boolean"},"location":{"type":"string","nullable":True}}},
        "Counselor":{"type":"object","properties":{"id":{"type":"integer"},"name":{"type":"string"},"slug":{"type":"string"},"avatar_url":{"type":"string","format":"uri-reference","nullable":True},"bio":{"type":"string","nullable":True},"about":{"type":"string","nullable":True},"degree":{"type":"string","nullable":True},"license_number":{"type":"string","nullable":True},"years_of_experience":{"type":"integer","nullable":True},"languages":{"type":"array","items":{"type":"string"},"nullable":True},"socials":{"type":"object","properties":{"instagram":{"type":"string","nullable":True},"telegram":{"type":"string","nullable":True},"whatsapp":{"type":"string","nullable":True},"website":{"type":"string","nullable":True},"linkedin":{"type":"string","nullable":True}}},"meta_title":{"type":"string","nullable":True},"meta_description":{"type":"string","nullable":True},"is_active":{"type":"boolean"},"is_public":{"type":"boolean"},"specialties":{"type":"array","items":{"$ref":"#/components/schemas/Specialty"}}}},
        "Specialty":{"type":"object","properties":{"id":{"type":"integer"},"title":{"type":"string"},"slug":{"type":"string"},"is_active":{"type":"boolean"}}},
        "CmsEntry":{"type":"object","properties":{"id":{"type":"integer"},"type":{"type":"string","enum":["pages","posts","categories","gallery","services","faqs","banners","homepage_sections"]},"title":{"type":"string"},"slug":{"type":"string"},"body":{"type":"string","nullable":True},"image":{"type":"string","nullable":True},"extra":{"type":"object","additionalProperties":True,"nullable":True},"status":{"type":"string","enum":["draft","published"]},"is_active":{"type":"boolean"},"published_at":{"type":"string","format":"date-time","nullable":True},"seo_title":{"type":"string","nullable":True},"seo_description":{"type":"string","nullable":True},"sort_order":{"type":"integer"},"created_at":{"type":"string","format":"date-time"},"updated_at":{"type":"string","format":"date-time"}}},
        "ClinicalRecord":{"type":"object","properties":{"id":{"type":"integer"},"client_id":{"type":"integer"},"primary_counselor_id":{"type":"integer","nullable":True},"client_concerns":{"type":"string","nullable":True},"treatment_goals":{"type":"array","items":{"type":"string"},"nullable":True},"progress_summary":{"type":"string","nullable":True},"updated_by":{"type":"integer","nullable":True},"created_at":{"type":"string","format":"date-time","nullable":True},"updated_at":{"type":"string","format":"date-time","nullable":True}}},
        "ClinicalNoteList":{"type":"array","items":{"$ref":"#/components/schemas/ClinicalNote"}},
        "ClinicalNoteVersion":{"type":"object","properties":{"id":{"type":"integer"},"note_id":{"type":"integer"},"author_id":{"type":"integer"},"author_name":{"type":"string"},"version":{"type":"integer"},"snapshot":{"type":"object","additionalProperties":True},"changed_fields":{"type":"array","items":{"type":"string"}},"created_at":{"type":"string","format":"date-time"}}},
        "ClinicalNoteVersionList":{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer"},"note_id":{"type":"integer"},"author_id":{"type":"integer"},"author_name":{"type":"string"},"version":{"type":"integer"},"created_at":{"type":"string","format":"date-time"}}}},
        "IntakeForm":{"type":"object","properties":{"id":{"type":"integer"},"key":{"type":"string"},"title":{"type":"string"},"is_active":{"type":"boolean"},"version":{"type":"integer","nullable":True},"schema":{"type":"array","items":{"type":"object","properties":{"key":{"type":"string"},"label":{"type":"string"},"type":{"type":"string","enum":["text","textarea","number","boolean","select","multiselect","date"]},"required":{"type":"boolean"},"options":{"type":"array","items":{"type":"string"}}}}},"published_at":{"type":"string","format":"date-time","nullable":True}}},
        "IntakeFormList":{"type":"array","items":{"$ref":"#/components/schemas/IntakeForm"}},
        "IntakeFormVersion":{"type":"object","properties":{"id":{"type":"integer"},"intake_form_id":{"type":"integer"},"version":{"type":"integer"},"schema":{"type":"array","items":{"type":"object","additionalProperties":True}},"published_by":{"type":"integer"},"published_at":{"type":"string","format":"date-time"}}},
        "IntakeSubmission":{"type":"object","properties":{"id":{"type":"integer"},"form_version":{"type":"integer"},"submitted_at":{"type":"string","format":"date-time"}}},
        "ClinicalAnswer":{"type":"object","properties":{"id":{"type":"integer"},"form_title":{"type":"string"},"version":{"type":"integer"},"answers":{"type":"object","additionalProperties":True},"submitted_at":{"type":"string","format":"date-time"}}},
        "PaginatedClinicalAnswers":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/ClinicalAnswer"}}}},
        "ClinicalConsent":{"type":"object","properties":{"id":{"type":"integer"},"client_id":{"type":"integer"},"consent_key":{"type":"string"},"version":{"type":"integer"},"accepted":{"type":"boolean"},"accepted_at":{"type":"string","format":"date-time"},"ip_address":{"type":"string","nullable":True}}},
        "ClinicalConsentList":{"type":"array","items":{"$ref":"#/components/schemas/ClinicalConsent"}},
        "FollowUpTask":{"type":"object","properties":{"id":{"type":"integer"},"client_id":{"type":"integer"},"counselor_id":{"type":"integer"},"booking_id":{"type":"integer","nullable":True},"title":{"type":"string"},"details":{"type":"string","nullable":True},"due_date":{"type":"string","format":"date","nullable":True},"completed_at":{"type":"string","format":"date-time","nullable":True},"created_by":{"type":"integer"}}},
        "PaginatedFollowUps":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/FollowUpTask"}}}},
        "ClinicalAttachment":{"type":"object","properties":{"id":{"type":"integer"},"client_id":{"type":"integer"},"uploaded_by":{"type":"integer"},"original_name":{"type":"string"},"mime_type":{"type":"string"},"size_bytes":{"type":"integer"},"created_at":{"type":"string","format":"date-time"}}},
        "PaginatedAttachments":{"allOf":[{"$ref":"#/components/schemas/PaginatedResponse"}],"properties":{"data":{"type":"array","items":{"$ref":"#/components/schemas/ClinicalAttachment"}}}}
    }},
    "paths": paths,
}
(root / "docs/openapi.json").write_text(json.dumps(spec, ensure_ascii=False, indent=2) + "\n")
print(f"Wrote docs/openapi.json with {sum(len(v) for v in paths.values())} operations across {len(paths)} paths")
