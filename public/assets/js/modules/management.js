const CANVAS_KEY = 'sql-canvas.canvas-layout.v1';

export function initManagement(client, connection) {
    let selected = { schema: null, table: null };
    let graphData = null;
    let adminView = 'status';
    let importText = '';
    let schemaPayload = null;
    let accountMode = 'create';
    let sqlImportText = '';
    let selectedObject = null;
    let privilegeAccount = null;
    let relationshipSource = null;
    let canvasScale = 1;

    const emitView = (view) => document.dispatchEvent(new CustomEvent('sqlcanvas:view', { detail: { view } }));
    document.addEventListener('sqlcanvas:table-selected', (event) => { selected = event.detail; });
    document.addEventListener('sqlcanvas:schema-selected', (event) => { selected = { schema: event.detail.schema, table: selected.schema === event.detail.schema ? selected.table : null }; });
    document.addEventListener('sqlcanvas:object-selected', (event) => openDefinition(event.detail));
    document.querySelectorAll('[data-global-view]').forEach((button) => button.addEventListener('click', () => {
        const view = button.dataset.globalView;
        if (view === 'query') emitView('query');
        else if (view === 'canvas') { emitView('canvas'); loadCanvas(); }
        else if (view === 'server' || view === 'users') { adminView = view === 'users' ? 'users' : 'status'; emitView('admin'); loadAdmin(); }
        else emitView('home');
    }));

    async function loadCanvas() {
        const canvas = document.querySelector('[data-schema-canvas]');
        if (!selected.schema) {
            canvas.replaceChildren(message('Select a table or expand a database before opening the relationship canvas.'));
            return;
        }
        canvas.replaceChildren(message('Loading schema relationships…'));
        try {
            graphData = await client.get(`/databases/${encodeURIComponent(selected.schema)}/schema-graph`);
            document.querySelector('[data-canvas-title]').textContent = graphData.database;
            renderCanvas(graphData);
            connection.activity('Canvas loaded', `${graphData.tables.length} tables · ${graphData.relationships.length} relationships`);
        } catch (error) {
            canvas.replaceChildren(message(error instanceof Error ? error.message : 'The schema canvas could not be loaded.'));
        }
    }

    async function openDefinition(object) {
        selectedObject=object; emitView('definition');
        document.querySelector('[data-definition-type]').textContent=object.type;
        document.querySelector('[data-definition-name]').textContent=object.name;
        const output=document.querySelector('[data-definition-sql]'); output.textContent='Loading definition…';
        const eventStatus=document.querySelector('[data-definition-event-status]');eventStatus.hidden=true;
        try { const data=await client.get(`/databases/${encodeURIComponent(object.schema)}/objects/${encodeURIComponent(object.type)}/${encodeURIComponent(object.name)}`);selectedObject={...object,...data};output.textContent=data.definition||'Definition is empty.';document.querySelector('[data-selected-database]').textContent=object.schema;document.querySelector('[data-selected-object]').textContent=`${object.type} · ${object.name}`;if(object.type==='event'&&data.status){eventStatus.hidden=false;eventStatus.textContent=data.status==='ENABLED'?'Disable event':'Enable event';eventStatus.dataset.nextStatus=data.status==='ENABLED'?'DISABLE':'ENABLE';}connection.activity('Definition loaded',`${object.schema}.${object.name}`); }
        catch(error){output.textContent=error instanceof Error?error.message:'The definition could not be loaded.';}
    }

    async function changeEventStatus() {
        if(selectedObject?.type!=='event')return;
        const status=document.querySelector('[data-definition-event-status]').dataset.nextStatus;
        const target=`${selectedObject.schema}.${selectedObject.name}`;
        const expected=`${status} ${target}`;
        const confirmation=window.prompt(`Type ${expected} to confirm this event change.`,'');
        if(confirmation===null)return;
        try{await client.post(`/databases/${encodeURIComponent(selectedObject.schema)}/objects/event/${encodeURIComponent(selectedObject.name)}/status`,{status,confirmation});connection.activity('Event updated',`${target} · ${status.toLowerCase()}`);openDefinition(selectedObject);}
        catch(error){connection.activity('Event update failed',error instanceof Error?error.message:'The event status could not be changed.');}
    }

    async function dropDefinition() {
        if(!selectedObject?.definition)return;const target=`${selectedObject.schema}.${selectedObject.name}`;
        const confirmation=window.prompt(`Type ${target} to permanently drop this ${selectedObject.type}.`,''); if(confirmation===null)return;
        try{await client.post(`/databases/${encodeURIComponent(selectedObject.schema)}/objects/${encodeURIComponent(selectedObject.type)}/${encodeURIComponent(selectedObject.name)}/drop`,{confirmation});connection.activity('Object removed',`${selectedObject.type} ${target}`);emitView('home');document.dispatchEvent(new CustomEvent('sqlcanvas:connected',{detail:{connected:true}}));}
        catch(error){connection.activity('Drop failed',error instanceof Error?error.message:'The object could not be removed.');}
    }

    function renderCanvas(data) {
        const canvas = document.querySelector('[data-schema-canvas]');
        const board = document.createElement('div');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        board.className = 'canvas-board';
        board.style.zoom=String(canvasScale);
        svg.classList.add('canvas-edges');
        board.append(svg);
        const saved = loadLayout(data.database);
        data.tables.forEach((table, index) => {
            const card = document.createElement('article');
            const heading = document.createElement('h3');
            const columns = document.createElement('div');
            const position = saved[table.name] ?? { x: 30 + (index % 4) * 285, y: 30 + Math.floor(index / 4) * 300 };
            card.className = 'canvas-table';
            card.dataset.table = table.name;
            card.style.left = `${position.x}px`;
            card.style.top = `${position.y}px`;
            card.tabIndex = 0;
            heading.textContent = table.name;
            table.columns.slice(0, 18).forEach((column) => {
                const row = document.createElement('div');
                const badge = document.createElement('b');
                const name = document.createElement('span');
                const type = document.createElement('code');
                row.className = 'canvas-column';
                row.dataset.column=column.name;row.tabIndex=0;row.title='Select this field as a foreign-key endpoint';
                badge.textContent = column.columnKey || '·';
                name.textContent = column.name;
                type.textContent = column.type;
                row.append(badge, name, type);
                row.addEventListener('click',()=>selectRelationshipField(data.database,table.name,column.name,row));
                row.addEventListener('keydown',(event)=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();selectRelationshipField(data.database,table.name,column.name,row);}});
                columns.append(row);
            });
            card.append(heading, columns);
            makeDraggable(card, data.database, board, data);
            card.addEventListener('keydown', (event) => moveCardWithKeyboard(event, card, data.database, board, data));
            board.append(card);
        });
        canvas.replaceChildren(board);
        requestAnimationFrame(() => { drawEdges(board, data); drawMinimap(board); });
    }

    function drawEdges(board, data) {
        const svg = board.querySelector('.canvas-edges');
        svg.replaceChildren();
        data.relationships.forEach((relation) => {
            const source = board.querySelector(`[data-table="${CSS.escape(relation.sourceTable)}"]`);
            const target = board.querySelector(`[data-table="${CSS.escape(relation.targetTable)}"]`);
            if (!source || !target) return;
            const x1 = source.offsetLeft + source.offsetWidth;
            const y1 = source.offsetTop + 36;
            const x2 = target.offsetLeft;
            const y2 = target.offsetTop + 36;
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const middle = (x1 + x2) / 2;
            path.setAttribute('d', `M ${x1} ${y1} C ${middle} ${y1}, ${middle} ${y2}, ${x2} ${y2}`);
            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = `${relation.sourceTable}.${relation.sourceColumn} → ${relation.targetTable}.${relation.targetColumn}`;
            path.append(title);
            svg.append(path);
        });
    }

    function selectRelationshipField(database,table,column,row) {
        if(!document.querySelector('[data-table-schema-actions]') || document.querySelector('[data-table-schema-actions]').hidden)return;
        if(!relationshipSource){relationshipSource={database,table,column};document.querySelectorAll('.canvas-column').forEach((item)=>item.classList.remove('is-relation-source'));row.classList.add('is-relation-source');connection.activity('Relationship source selected',`${table}.${column} · choose a referenced field`);return;}
        const source=relationshipSource;relationshipSource=null;document.querySelectorAll('.canvas-column').forEach((item)=>item.classList.remove('is-relation-source'));if(source.table===table&&source.column===column)return;selected={schema:database,table:source.table};openSchema('ADD_FOREIGN_KEY');const form=document.querySelector('[data-schema-form]');form.elements.operation.value='ADD_FOREIGN_KEY';form.elements.name.value=`fk_${source.table}_${source.column}`.slice(0,64);form.elements.column.value=source.column;form.elements.targetDatabase.value=database;form.elements.targetTable.value=table;form.elements.targetColumn.value=column;connection.activity('Foreign key proposed',`${source.table}.${source.column} → ${table}.${column} · preview before applying`);
    }

    function drawMinimap(board) {
        board.querySelector('.canvas-minimap')?.remove();const cards=[...board.querySelectorAll('.canvas-table')];if(!cards.length)return;
        const width=Math.max(...cards.map((card)=>card.offsetLeft+card.offsetWidth),1),height=Math.max(...cards.map((card)=>card.offsetTop+card.offsetHeight),1);const map=document.createElement('div');map.className='canvas-minimap';map.setAttribute('aria-hidden','true');
        cards.forEach((card)=>{const dot=document.createElement('i');dot.style.left=`${card.offsetLeft/width*150+7}px`;dot.style.top=`${card.offsetTop/height*90+7}px`;dot.style.width=`${Math.max(5,card.offsetWidth/width*150)}px`;dot.style.height=`${Math.max(3,card.offsetHeight/height*90)}px`;map.append(dot);});board.append(map);
    }

    function makeDraggable(card, database, board, data) {
        const heading = card.querySelector('h3');
        heading.style.cursor = 'move';
        heading.addEventListener('pointerdown', (event) => {
            const start = { x: event.clientX, y: event.clientY, left: card.offsetLeft, top: card.offsetTop };
            heading.setPointerCapture(event.pointerId);
            const move = (next) => {
                card.style.left = `${Math.max(0, start.left + next.clientX - start.x)}px`;
                card.style.top = `${Math.max(0, start.top + next.clientY - start.y)}px`;
                drawEdges(board, data); drawMinimap(board);
            };
            const up = () => { heading.removeEventListener('pointermove', move); saveLayout(database, board); };
            heading.addEventListener('pointermove', move);
            heading.addEventListener('pointerup', up, { once: true });
        });
    }

    function moveCardWithKeyboard(event, card, database, board, data) {
        const delta = event.shiftKey ? 40 : 10;
        const moves = { ArrowLeft: [-delta, 0], ArrowRight: [delta, 0], ArrowUp: [0, -delta], ArrowDown: [0, delta] };
        if (!moves[event.key]) return;
        event.preventDefault();
        card.style.left = `${Math.max(0, card.offsetLeft + moves[event.key][0])}px`;
        card.style.top = `${Math.max(0, card.offsetTop + moves[event.key][1])}px`;
        drawEdges(board, data); drawMinimap(board);
        saveLayout(database, board);
    }

    function loadLayout(database) {
        try { return JSON.parse(localStorage.getItem(CANVAS_KEY) ?? '{}')[database] ?? {}; } catch { return {}; }
    }

    function saveLayout(database, board) {
        let all = {};
        try { all = JSON.parse(localStorage.getItem(CANVAS_KEY) ?? '{}'); } catch { all = {}; }
        all[database] = Object.fromEntries([...board.querySelectorAll('.canvas-table')].map((card) => [card.dataset.table, { x: card.offsetLeft, y: card.offsetTop }]));
        localStorage.setItem(CANVAS_KEY, JSON.stringify(all));
    }

    async function loadAdmin() {
        const content = document.querySelector('[data-admin-content]');
        document.querySelector('[data-admin-title]').textContent = adminView === 'users' ? 'Users' : adminView === 'processes' ? 'Processes' : adminView==='variables'?'Variables':'Server';
        const createUser = document.querySelector('[data-admin-create-user]'); if (createUser) createUser.hidden = adminView !== 'users' || createUser.dataset.capabilityHidden === 'true';
        document.querySelectorAll('[data-admin-view]').forEach((button) => button.setAttribute('aria-selected', String(button.dataset.adminView === adminView)));
        content.replaceChildren(message('Loading live server information…'));
        try {
            if (adminView === 'status') renderMetrics(content, await client.get('/admin/server-status'));
            if (adminView === 'variables') renderVariables(content, await client.get('/admin/variables'));
            if (adminView === 'processes') renderAdminRows(content, (await client.get('/admin/processes')).processes, 'process');
            if (adminView === 'users') renderAdminRows(content, (await client.get('/admin/users')).users, 'user');
        } catch (error) {
            content.replaceChildren(message(error instanceof Error ? error.message : 'Administration data could not be loaded.'));
        }
    }

    function renderMetrics(container, data) {
        const grid = document.createElement('div');
        grid.className = 'metric-grid';
        Object.entries({ ...data.status, ...data.variables }).forEach(([key, value]) => {
            const card = document.createElement('article');
            const label = document.createElement('span');
            const strong = document.createElement('strong');
            card.className = 'metric-card'; label.textContent = key.replaceAll('_', ' '); strong.textContent = value; card.append(label, strong); grid.append(card);
        });
        container.replaceChildren(grid);
    }

    function renderTable(container, rows) {
        if (!rows.length) { container.replaceChildren(message('No visible records.')); return; }
        const table = document.createElement('table'); table.className = 'admin-table';
        const keys = Object.keys(rows[0]);
        const head = document.createElement('tr'); keys.forEach((key) => { const th = document.createElement('th'); th.textContent = key; head.append(th); });
        const thead = document.createElement('thead'); thead.append(head); const body = document.createElement('tbody');
        rows.forEach((row) => { const tr = document.createElement('tr'); keys.forEach((key) => { const td = document.createElement('td'); td.textContent = row[key] === null ? 'NULL' : String(row[key]); tr.append(td); }); body.append(tr); });
        table.append(thead, body); container.replaceChildren(table);
    }

    function renderAdminRows(container, rows, kind) {
        if (!rows.length) { container.replaceChildren(message('No visible records.')); return; }
        const writable = Boolean(document.querySelector('[data-admin-create-user]'));
        const table = document.createElement('table'); table.className = 'admin-table';
        const keys = Object.keys(rows[0]);
        const head = document.createElement('tr'); keys.forEach((key) => { const th=document.createElement('th'); th.textContent=key; head.append(th); });
        if (writable) { const th=document.createElement('th'); th.textContent='Actions'; head.append(th); }
        const thead=document.createElement('thead'); thead.append(head); const body=document.createElement('tbody');
        rows.forEach((row) => {
            const tr=document.createElement('tr'); keys.forEach((key) => { const td=document.createElement('td'); td.textContent=row[key]===null?'NULL':String(row[key]); tr.append(td); });
            if (writable) {
                const td=document.createElement('td'); const button=document.createElement('button'); button.type='button'; button.className='danger-button compact-action';
                if (kind === 'process') { button.textContent='Terminate'; button.addEventListener('click', () => killProcess(row.Id ?? row.id)); }
                else {
                    const privileges=document.createElement('button');privileges.type='button';privileges.className='secondary-button compact-action';privileges.textContent='Privileges';privileges.addEventListener('click',()=>openPrivileges(String(row.user),String(row.host)));
                    button.textContent='Remove'; button.addEventListener('click', () => openAccount('drop', String(row.user), String(row.host))); td.append(privileges,button);
                }
                if(kind==='process')td.append(button); tr.append(td);
            }
            body.append(tr);
        });
        table.append(thead,body); container.replaceChildren(table);
    }

    function renderVariables(container,data){const wrap=document.createElement('div');const search=document.createElement('input');search.type='search';search.placeholder='Filter variables…';search.className='admin-variable-search';const tableHost=document.createElement('div');const draw=()=>{const needle=search.value.trim().toLowerCase();const rows=data.variables.filter((row)=>String(row.Variable_name??Object.values(row)[0]??'').toLowerCase().includes(needle));if(!rows.length){tableHost.replaceChildren(message('No matching variables.'));return;}const table=document.createElement('table');table.className='admin-table';const head=document.createElement('tr');['Variable','Value','Actions'].forEach((value)=>{const th=document.createElement('th');th.textContent=value;head.append(th);});const thead=document.createElement('thead');thead.append(head);const body=document.createElement('tbody');rows.forEach((row)=>{const name=String(row.Variable_name??Object.values(row)[0]??'');const value=String(row.Value??Object.values(row)[1]??'');const tr=document.createElement('tr');const nameCell=document.createElement('td');nameCell.textContent=name;const valueCell=document.createElement('td');valueCell.textContent=value;const action=document.createElement('td');if(data.editable.includes(name)&&document.querySelector('[data-app-shell]').dataset.canSetGlobalVariables==='true'){const button=document.createElement('button');button.type='button';button.className='secondary-button compact-action';button.textContent='Set global';button.addEventListener('click',()=>setGlobalVariable(name,value));action.append(button);}tr.append(nameCell,valueCell,action);body.append(tr);});table.append(thead,body);tableHost.replaceChildren(table);};search.addEventListener('input',draw);wrap.append(search,tableHost);container.replaceChildren(wrap);draw();}

    async function setGlobalVariable(name,currentValue){const value=window.prompt(`Set global ${name}. Current value: ${currentValue}` ,currentValue);if(value===null)return;const expected=`SET GLOBAL ${name}`;const confirmation=window.prompt(`This changes server-wide behavior. Type ${expected} to confirm.`,'');if(confirmation===null)return;try{const data=await client.post('/admin/variables/set',{name,value,confirmation});connection.activity('Global variable changed',`${data.name} = ${data.value}`);loadAdmin();}catch(error){connection.activity('Variable change failed',error instanceof Error?error.message:'The server rejected the change.');}}

    async function killProcess(id) {
        const target=String(id ?? '');
        if (!target || !window.confirm(`Terminate database process ${target}? This can roll back its active transaction.`)) return;
        try { await client.post('/admin/processes/kill', { id: Number(target), confirmation: target }); connection.activity('Process terminated', `Process ${target}`); loadAdmin(); }
        catch (error) { connection.activity('Termination failed', error instanceof Error?error.message:'The process could not be terminated.'); }
    }

    function openAccount(mode, username='', host='%') {
        accountMode=mode; const form=document.querySelector('[data-account-form]'); if (!form) return; form.reset();
        form.elements.username.value=username; form.elements.host.value=host;
        form.elements.username.readOnly=mode==='drop'; form.elements.host.readOnly=mode==='drop';
        document.querySelector('[data-account-password-field]').hidden=mode==='drop'; form.elements.password.required=mode==='create';
        const target=`${username}@${host}`; document.querySelector('[data-account-title]').textContent=mode==='drop'?'Remove user':'Create user';
        document.querySelector('[data-account-confirm-label]').textContent=mode==='drop'?`Type ${target} to confirm`:'Type username@host to confirm';
        document.querySelector('[data-account-submit]').textContent=mode==='drop'?'Remove user':'Create user';
        document.querySelector('[data-account-warning]').textContent=mode==='drop'?'This permanently removes the account. Existing sessions may remain active until disconnected.':'Creates a login account without grants. Assign only the minimum privileges required using audited SQL.';
        document.querySelector('[data-account-error]').hidden=true; document.querySelector('[data-account-dialog]').showModal();
    }

    async function submitAccount(event) {
        event.preventDefault(); const form=new FormData(event.currentTarget); const payload=Object.fromEntries(form.entries());
        try { await client.post(accountMode==='drop'?'/admin/users/drop':'/admin/users/create', payload); document.querySelector('[data-account-dialog]').close(); connection.activity(accountMode==='drop'?'User removed':'User created', `${payload.username}@${payload.host}`); loadAdmin(); }
        catch (error) { showError('[data-account-error]',error); }
    }

    async function openPrivileges(username,host) {
        const dialog=document.querySelector('[data-privilege-dialog]');if(!dialog)return;privilegeAccount={username,host};document.querySelector('[data-privilege-title]').textContent=`${username}@${host}`;document.querySelector('[data-privilege-error]').hidden=true;
        try{const data=await client.get('/admin/users/grants',{username,host});const list=document.querySelector('[data-current-grants]');list.replaceChildren();data.grants.forEach((grant)=>{const row=document.createElement('div');row.textContent=grant;list.append(row);});const options=document.querySelector('[data-privilege-options]');options.replaceChildren();const legend=document.createElement('legend');legend.textContent='Privileges';options.append(legend);data.availablePrivileges.forEach((privilege)=>{const label=document.createElement('label');const input=document.createElement('input');input.type='checkbox';input.name='privileges';input.value=privilege;label.append(input,document.createTextNode(privilege));options.append(label);});updatePrivilegeConfirmation();dialog.showModal();}
        catch(error){connection.activity('Privileges unavailable',error instanceof Error?error.message:'Privileges could not be loaded.');}
    }

    function updatePrivilegeConfirmation(){if(!privilegeAccount)return;const action=document.querySelector('[data-privilege-form]').elements.action.value.toUpperCase();document.querySelector('[data-privilege-confirm-label]').textContent=`Type ${action} ${privilegeAccount.username}@${privilegeAccount.host} to confirm`;}
    async function submitPrivileges(event){event.preventDefault();const form=new FormData(event.currentTarget);const action=String(form.get('action'));const payload={...privilegeAccount,scope:form.get('scope'),database:form.get('database'),table:form.get('table'),confirmation:form.get('confirmation'),privileges:form.getAll('privileges')};try{await client.post(`/admin/users/${action}`,payload);document.querySelector('[data-privilege-dialog]').close();connection.activity('Privileges changed',`${action} · ${privilegeAccount.username}@${privilegeAccount.host}`);loadAdmin();}catch(error){showError('[data-privilege-error]',error);}}

    function download(name, content, type) {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const link = document.createElement('a'); link.href = url; link.download = name; link.click(); URL.revokeObjectURL(url);
    }

    function canvasSvg() {
        const board=document.querySelector('.canvas-board'); if (!board || !graphData) return null;
        const cards=[...board.querySelectorAll('.canvas-table')];
        const width=Math.max(900,...cards.map((card)=>card.offsetLeft+card.offsetWidth+30));
        const height=Math.max(600,...cards.map((card)=>card.offsetTop+card.offsetHeight+30));
        const escape=(value)=>String(value).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
        const edges=[...board.querySelectorAll('.canvas-edges path')].map((path)=>`<path d="${escape(path.getAttribute('d'))}" fill="none" stroke="#5b70a6" stroke-width="2"/>`).join('');
        const nodes=cards.map((card)=>{const table=graphData.tables.find((item)=>item.name===card.dataset.table);const x=card.offsetLeft,y=card.offsetTop,w=card.offsetWidth,h=card.offsetHeight;const rows=(table?.columns??[]).slice(0,18).map((column,index)=>`<text x="${x+14}" y="${y+62+index*18}" fill="#aab4c5" font-family="monospace" font-size="11">${escape(column.columnKey||'·')}  ${escape(column.name)}  ${escape(column.type)}</text>`).join('');return `<g><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="9" fill="#121a24" stroke="#33445b"/><rect x="${x}" y="${y}" width="${w}" height="38" rx="9" fill="#192432"/><text x="${x+14}" y="${y+25}" fill="#f2f6fb" font-family="sans-serif" font-size="14" font-weight="700">${escape(card.dataset.table)}</text>${rows}</g>`;}).join('');
        return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="#0d141d"/>${edges}${nodes}</svg>`;
    }

    function exportCanvasSvg() { const svg=canvasSvg(); if (svg) download(`${graphData.database}-canvas.svg`,svg,'image/svg+xml'); }
    function exportCanvasPng() {
        const svg=canvasSvg(); if (!svg) return; const image=new Image();
        image.onload=()=>{const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;canvas.getContext('2d').drawImage(image,0,0);canvas.toBlob((blob)=>{if(!blob)return;const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=`${graphData.database}-canvas.png`;link.click();URL.revokeObjectURL(url);},'image/png');};
        image.src=`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    }

    function filterCanvas() {
        const needle=document.querySelector('[data-canvas-search]').value.trim().toLowerCase();document.querySelectorAll('.canvas-table').forEach((card)=>{const match=!needle||card.dataset.table.toLowerCase().includes(needle)||card.textContent.toLowerCase().includes(needle);card.classList.toggle('is-dimmed',Boolean(needle)&&!match);card.classList.toggle('is-match',Boolean(needle)&&match);});
    }

    function autoLayout() { if(!graphData)return;let all={};try{all=JSON.parse(localStorage.getItem(CANVAS_KEY)??'{}');}catch{}delete all[graphData.database];localStorage.setItem(CANVAS_KEY,JSON.stringify(all));renderCanvas(graphData); }
    function zoomCanvas(delta){canvasScale=Math.min(1.6,Math.max(.5,Math.round((canvasScale+delta)*10)/10));const board=document.querySelector('.canvas-board');if(board)board.style.zoom=String(canvasScale);document.querySelector('[data-canvas-zoom-reset]').textContent=`${Math.round(canvasScale*100)}%`;}

    async function compareSnapshot(event) {
        const file=event.target.files[0];if(!file||!graphData)return;
        try{const old=JSON.parse(await file.text());if(!Array.isArray(old.tables)||!Array.isArray(old.relationships))throw new Error('Choose a SQL Canvas schema JSON snapshot.');const currentNames=new Set(graphData.tables.map((table)=>table.name)),oldNames=new Set(old.tables.map((table)=>table.name));const added=[...currentNames].filter((name)=>!oldNames.has(name)),removed=[...oldNames].filter((name)=>!currentNames.has(name));const changed=graphData.tables.filter((table)=>{const before=old.tables.find((item)=>item.name===table.name);return before&&JSON.stringify(before.columns)!==JSON.stringify(table.columns);}).map((table)=>table.name);const rel=(value)=>new Set(value.map((item)=>`${item.sourceTable}.${item.sourceColumn}->${item.targetTable}.${item.targetColumn}`));const nowRel=rel(graphData.relationships),oldRel=rel(old.relationships);renderDiff({added,removed,changed,relationshipsAdded:[...nowRel].filter((item)=>!oldRel.has(item)),relationshipsRemoved:[...oldRel].filter((item)=>!nowRel.has(item))});document.querySelector('[data-canvas-diff-dialog]').showModal();}
        catch(error){connection.activity('Snapshot comparison failed',error instanceof Error?error.message:'The snapshot could not be read.');}finally{event.target.value='';}
    }

    function renderDiff(diff) {const container=document.querySelector('[data-canvas-diff]');container.replaceChildren();Object.entries(diff).forEach(([title,items])=>{const section=document.createElement('section');const heading=document.createElement('h3');heading.textContent=title.replace(/([A-Z])/g,' $1').replace(/^./,(value)=>value.toUpperCase());section.append(heading);if(!items.length){const p=document.createElement('p');p.textContent='No changes';section.append(p);}items.forEach((item)=>{const p=document.createElement('p');p.textContent=item;section.append(p);});container.append(section);});}

    async function exportDatabaseSql() {
        if (!selected.schema) return;
        try { const data=await client.get(`/databases/${encodeURIComponent(selected.schema)}/export-sql`,{mode:'both',rowLimit:5000}); download(data.filename,data.content,'application/sql'); const detail=data.truncatedTables.length?`Rows capped for: ${data.truncatedTables.join(', ')}`:`${data.mode} backup`; connection.activity('SQL backup ready',detail); }
        catch(error){connection.activity('Backup failed',error instanceof Error?error.message:'The backup could not be created.');}
    }

    async function showDatabaseDetails(){if(!selected.schema)return;try{const data=await client.get(`/databases/${encodeURIComponent(selected.schema)}/statistics`);document.querySelector('[data-database-details-title]').textContent=data.database;const metrics=document.querySelector('[data-database-metrics]');renderMetrics(metrics,{status:{Tables:data.tableCount,Views:data.viewCount,Routines:data.routineCount,Triggers:data.triggerCount,Events:data.eventCount,'Estimated rows':data.estimatedRows,'Data bytes':data.dataBytes,'Index bytes':data.indexBytes},variables:{}});renderTable(document.querySelector('[data-database-tables]'),data.tables);document.querySelector('[data-database-details-dialog]').showModal();}catch(error){connection.activity('Database details failed',error instanceof Error?error.message:'Details could not be loaded.');}}

    function openSqlImport() {
        if (!selected.schema) return; sqlImportText=''; const form=document.querySelector('[data-sql-import-form]');form.reset();document.querySelector('[data-sql-import-start]').disabled=true;document.querySelector('[data-sql-import-confirm-field]').hidden=true;document.querySelector('[data-sql-import-preview]').replaceChildren(message(`Import into ${selected.schema}`));document.querySelector('[data-sql-import-error]').hidden=true;document.querySelector('[data-sql-import-dialog]').showModal();
    }

    async function previewSqlImport() {
        const file=document.querySelector('[data-sql-import-file]').files[0]; if(!file)return; sqlImportText=await file.text();
        try { const data=await client.post(`/databases/${encodeURIComponent(selected.schema)}/import-sql/preview`,{sql:sqlImportText});const preview=document.querySelector('[data-sql-import-preview]');preview.replaceChildren(message(`${data.statementCount} statements · ${data.destructiveStatementCount} potentially destructive`));const field=document.querySelector('[data-sql-import-confirm-field]');field.hidden=false;document.querySelector('[data-sql-import-confirm-label]').textContent=`Type ${data.confirmation} to confirm`;document.querySelector('[data-sql-import-start]').disabled=false; }
        catch(error){showError('[data-sql-import-error]',error);}
    }

    async function importSql(event) {
        event.preventDefault();const confirmation=new FormData(event.currentTarget).get('confirmation');
        try{const data=await client.post(`/databases/${encodeURIComponent(selected.schema)}/import-sql/start`,{sql:sqlImportText,confirmation});document.querySelector('[data-sql-import-dialog]').close();connection.activity('SQL import complete',`${data.executedStatements} statements executed in ${selected.schema}`);document.dispatchEvent(new CustomEvent('sqlcanvas:connected',{detail:{connected:true}}));}
        catch(error){showError('[data-sql-import-error]',error);}
    }

    async function fullExport(format) {
        if (!selected.schema || !selected.table) return;
        try {
            const data = await client.get(`/databases/${encodeURIComponent(selected.schema)}/tables/${encodeURIComponent(selected.table)}/export`, { format });
            download(data.filename, data.content, format === 'json' ? 'application/json' : 'text/csv');
            connection.activity('Export ready', `${data.filename} · limited to ${data.truncatedAt} rows`);
        } catch (error) { connection.activity('Export failed', error instanceof Error ? error.message : 'Export error'); }
    }

    function openImport() {
        if (!selected.table) return;
        importText = '';
        document.querySelector('[data-import-preview]').replaceChildren(message(`Import into ${selected.schema}.${selected.table}`));
        document.querySelector('[data-import-start]').disabled = true;
        document.querySelector('[data-import-dialog]').showModal();
    }

    async function previewImport() {
        const file = document.querySelector('[data-import-file]').files[0];
        if (!file) return;
        importText = await file.text();
        const delimiter = document.querySelector('[data-import-delimiter]').value || ',';
        try {
            const data = await client.post(`/databases/${encodeURIComponent(selected.schema)}/tables/${encodeURIComponent(selected.table)}/import/preview`, { csv: importText, delimiter });
            const preview = document.querySelector('[data-import-preview]');
            renderTable(preview, data.preview.map((row) => Object.fromEntries(data.headers.map((header, index) => [header, row[index] ?? '']))));
            document.querySelector('[data-import-start]').disabled = !data.canImport;
            if (!data.canImport) throw new Error(`Unknown headers: ${data.unknownHeaders.join(', ')}`);
        } catch (error) { showError('[data-import-error]', error); }
    }

    async function importRows(event) {
        event.preventDefault();
        try {
            const data = await client.post(`/databases/${encodeURIComponent(selected.schema)}/tables/${encodeURIComponent(selected.table)}/import/start`, { csv: importText, delimiter: document.querySelector('[data-import-delimiter]').value || ',', nullValue: document.querySelector('[data-import-null]').value, confirmation: `${selected.schema}.${selected.table}` });
            document.querySelector('[data-import-dialog]').close();
            connection.activity('CSV imported', `${data.insertedRows} rows into ${selected.schema}.${selected.table}`);
            document.dispatchEvent(new CustomEvent('sqlcanvas:table-selected', { detail: selected }));
        } catch (error) { showError('[data-import-error]', error); }
    }

    function openSchema(operation) {
        schemaPayload = { operation };
        const fields = document.querySelector('[data-schema-fields]'); fields.replaceChildren();
        document.querySelector('[data-schema-preview]').hidden = true; document.querySelector('[data-schema-apply]').disabled = true;
        if (operation === 'CREATE_DATABASE') {
            document.querySelector('[data-schema-title]').textContent = 'Create database';
            fields.append(field('Database name', 'database'), field('Character set', 'charset', 'utf8mb4'), field('Collation', 'collation', 'utf8mb4_unicode_ci'));
        } else if (operation === 'COPY_DATABASE') {
            if(!selected.schema)return;document.querySelector('[data-schema-title]').textContent=`Copy ${selected.schema}`;schemaPayload.source=selected.schema;fields.append(field('Source database','source',selected.schema),field('Target database','target'),field('Character set','charset','utf8mb4'),field('Collation','collation','utf8mb4_unicode_ci'),selectField('Copy scope','includeData',[['no','Structure only'],['yes','Structure and rows']]));
        } else if (operation === 'DROP_DATABASE') {
            if(!selected.schema)return;document.querySelector('[data-schema-title]').textContent=`Drop database ${selected.schema}`;schemaPayload.database=selected.schema;
        } else if (operation === 'CREATE_TABLE') {
            if (!selected.schema) { connection.activity('Select a database', 'Expand a database before creating a table.'); return; }
            document.querySelector('[data-schema-title]').textContent = `Create table in ${selected.schema}`;
            schemaPayload.database = selected.schema;
            fields.append(field('Table name', 'table'), selectField('Storage engine', 'engine', [['InnoDB','InnoDB'],['MyISAM','MyISAM']]), field('Character set', 'charset', 'utf8mb4'));
            const editor=document.createElement('div'); editor.className='column-editor'; editor.dataset.columnEditor='';
            const heading=document.createElement('div'); heading.className='column-editor-heading'; heading.textContent='Columns';
            const add=document.createElement('button'); add.type='button'; add.className='secondary-button'; add.textContent='Add column'; add.addEventListener('click',()=>editor.append(columnRow()));
            heading.append(add); editor.append(heading, columnRow('id','INT','',true,true), columnRow('name','VARCHAR','255'));
            fields.append(editor);
        } else {
            document.querySelector('[data-schema-title]').textContent = 'Table action';
            fields.append(selectField('Operation', 'operation', [['TRUNCATE_TABLE','Empty / TRUNCATE'],['DROP_TABLE','Drop table'],['RENAME_TABLE','Rename table'],['COPY_TABLE','Copy table structure'],['ADD_COLUMN','Add column'],['MODIFY_COLUMN','Change column definition'],['DROP_COLUMN','Drop column'],['RENAME_COLUMN','Rename column'],['ADD_INDEX','Add index'],['DROP_INDEX','Drop index'],['ADD_FOREIGN_KEY','Add foreign key'],['DROP_FOREIGN_KEY','Drop foreign key'],['MAINTAIN_TABLE','Analyze / check / optimize / repair']]), field('Index or constraint name', 'name'), field('Index columns', 'columns', '', 'Comma-separated for Add index'), field('Source or column name', 'column'), selectField('Column type','columnType',[['VARCHAR','VARCHAR'],['INT','INT'],['BIGINT','BIGINT'],['DECIMAL','DECIMAL'],['TEXT','TEXT'],['DATE','DATE'],['DATETIME','DATETIME'],['TIMESTAMP','TIMESTAMP'],['JSON','JSON'],['BOOLEAN','BOOLEAN']]), field('Column size', 'columnSize'), field('Default value','columnDefault'), selectField('Nullable','columnNullable',[['no','Required'],['yes','Nullable']]), selectField('Unsigned','columnUnsigned',[['no','Signed / not numeric'],['yes','Unsigned']]), field('Generated expression','generatedExpression','','Optional'),selectField('Generated storage','generatedStored',[['no','Virtual'],['yes','Stored']]), field('Referenced database', 'targetDatabase', selected.schema), field('Target table or new table name', 'targetTable'), field('Referenced or new column name', 'targetColumn'), selectField('On delete','onDelete',[['RESTRICT','RESTRICT'],['CASCADE','CASCADE'],['SET NULL','SET NULL'],['NO ACTION','NO ACTION']]), selectField('On update','onUpdate',[['RESTRICT','RESTRICT'],['CASCADE','CASCADE'],['SET NULL','SET NULL'],['NO ACTION','NO ACTION']]), selectField('Maintenance action','maintenance',[['ANALYZE','ANALYZE'],['CHECK','CHECK'],['OPTIMIZE','OPTIMIZE'],['REPAIR','REPAIR']]));
            schemaPayload.database = selected.schema; schemaPayload.table = selected.table;
        }
        document.querySelector('[data-schema-dialog]').showModal();
    }

    function field(label, name, value = '', help = '') {
        const wrapper = document.createElement('label'); const span = document.createElement('span'); const input = document.createElement('input');
        wrapper.className = 'field field-wide'; span.textContent = help ? `${label} · ${help}` : label; input.name = name; input.value = value; wrapper.append(span, input); return wrapper;
    }

    function selectField(label, name, options) {
        const wrapper = document.createElement('label'); const span = document.createElement('span'); const select = document.createElement('select');
        wrapper.className = 'field field-wide'; span.textContent = label; select.name = name; options.forEach(([value,text]) => { const option = document.createElement('option'); option.value=value; option.textContent=text; select.append(option); }); wrapper.append(span,select); return wrapper;
    }

    function columnRow(name='', type='VARCHAR', size='', primary=false, autoIncrement=false) {
        const row=document.createElement('div'); row.className='column-row';
        const nameInput=document.createElement('input'); nameInput.placeholder='Column name'; nameInput.dataset.columnName=''; nameInput.value=name;
        const typeSelect=document.createElement('select'); typeSelect.dataset.columnType=''; ['INT','BIGINT','VARCHAR','TEXT','DECIMAL','DATE','DATETIME','TIMESTAMP','JSON','BOOLEAN'].forEach((value)=>{const option=document.createElement('option');option.value=value;option.textContent=value;option.selected=value===type;typeSelect.append(option);});
        const sizeInput=document.createElement('input'); sizeInput.placeholder='Size'; sizeInput.dataset.columnSize=''; sizeInput.value=size;
        const defaultInput=document.createElement('input'); defaultInput.placeholder='Default'; defaultInput.dataset.columnDefault='';
        const generatedInput=document.createElement('input');generatedInput.placeholder='Generated expression';generatedInput.dataset.columnGenerated='';
        const required=document.createElement('label'); const nullable=document.createElement('input'); nullable.type='checkbox'; nullable.dataset.columnNullable=''; required.append(nullable,document.createTextNode(' Nullable'));
        const pkLabel=document.createElement('label'); const pk=document.createElement('input');pk.type='checkbox';pk.dataset.columnPrimary='';pk.checked=primary;pkLabel.append(pk,document.createTextNode(' PK'));
        const aiLabel=document.createElement('label'); const ai=document.createElement('input');ai.type='checkbox';ai.dataset.columnAuto='';ai.checked=autoIncrement;aiLabel.append(ai,document.createTextNode(' Auto'));
        const unsignedLabel=document.createElement('label');const unsigned=document.createElement('input');unsigned.type='checkbox';unsigned.dataset.columnUnsigned='';unsignedLabel.append(unsigned,document.createTextNode(' Unsigned'));
        const uniqueLabel=document.createElement('label');const unique=document.createElement('input');unique.type='checkbox';unique.dataset.columnUnique='';uniqueLabel.append(unique,document.createTextNode(' Unique'));
        const storedLabel=document.createElement('label');const stored=document.createElement('input');stored.type='checkbox';stored.dataset.columnStored='';storedLabel.append(stored,document.createTextNode(' Stored'));
        const remove=document.createElement('button');remove.type='button';remove.className='icon-button compact';remove.textContent='×';remove.setAttribute('aria-label','Remove column');remove.addEventListener('click',()=>row.remove());
        row.append(nameInput,typeSelect,sizeInput,defaultInput,generatedInput,required,pkLabel,aiLabel,unsignedLabel,uniqueLabel,storedLabel,remove); return row;
    }

    async function previewSchema() {
        const form = new FormData(document.querySelector('[data-schema-form]'));
        const operation = form.get('operation') || schemaPayload.operation;
        schemaPayload = { ...schemaPayload, operation, database: form.get('database') || schemaPayload.database, table: form.get('table') || schemaPayload.table, engine: form.get('engine'), charset: form.get('charset'), collation: form.get('collation'), name: form.get('name'), columns: String(form.get('columns') || '').split(',').map((v) => v.trim()).filter(Boolean), column:form.get('column'),targetDatabase:form.get('targetDatabase'),targetTable:form.get('targetTable'),targetColumn:form.get('targetColumn'),onDelete:form.get('onDelete'),onUpdate:form.get('onUpdate'),maintenance:form.get('maintenance'),source:form.get('source')||schemaPayload.source,target:form.get('target'),includeData:form.get('includeData')==='yes' };
        if(operation==='ADD_COLUMN'||operation==='MODIFY_COLUMN')schemaPayload.columnDefinition={name:form.get('column'),type:form.get('columnType'),size:form.get('columnSize'),nullable:form.get('columnNullable')==='yes',unsigned:form.get('columnUnsigned')==='yes',default:form.get('columnDefault'),generatedExpression:form.get('generatedExpression'),generatedStored:form.get('generatedStored')==='yes'};
        if (operation === 'CREATE_TABLE') schemaPayload.columns=[...document.querySelectorAll('[data-column-editor] .column-row')].map((row)=>({name:row.querySelector('[data-column-name]').value.trim(),type:row.querySelector('[data-column-type]').value,size:row.querySelector('[data-column-size]').value.trim(),default:row.querySelector('[data-column-default]').value,generatedExpression:row.querySelector('[data-column-generated]').value.trim(),generatedStored:row.querySelector('[data-column-stored]').checked,nullable:row.querySelector('[data-column-nullable]').checked,primary:row.querySelector('[data-column-primary]').checked,autoIncrement:row.querySelector('[data-column-auto]').checked,unsigned:row.querySelector('[data-column-unsigned]').checked,unique:row.querySelector('[data-column-unique]').checked})).filter((column)=>column.name);
        try {
            const data = await client.post(operation==='COPY_DATABASE'?'/databases/copy/preview':'/schema/preview', schemaPayload);
            schemaPayload.confirmation = data.confirmation;
            document.querySelector('[data-schema-sql]').textContent = data.sql;
            const warnings = document.querySelector('[data-schema-warnings]'); warnings.replaceChildren(); data.warnings.forEach((warning) => { const li=document.createElement('li'); li.textContent=warning; warnings.append(li); });
            document.querySelector('[data-schema-preview]').hidden = false;
            const fields = document.querySelector('[data-schema-fields]');
            const existing = fields.querySelector('[name="confirmation"]'); if (existing) existing.closest('label').remove();
            fields.append(field(`Type ${data.confirmation} to confirm`, 'confirmation'));
            document.querySelector('[data-schema-apply]').disabled = false;
        } catch (error) { showError('[data-schema-error]', error); }
    }

    async function applySchema(event) {
        event.preventDefault();
        const confirmation = new FormData(event.currentTarget).get('confirmation');
        try {
            const data = await client.post(schemaPayload.operation==='COPY_DATABASE'?'/databases/copy/start':'/schema/apply', { ...schemaPayload, confirmation });
            document.querySelector('[data-schema-dialog]').close(); connection.activity(schemaPayload.operation==='COPY_DATABASE'?'Database copied':'Schema changed', schemaPayload.operation==='COPY_DATABASE'?`${data.tablesCopied} tables copied to ${data.target}`:`${data.durationMs} ms · ${data.sql}`);
            document.dispatchEvent(new CustomEvent('sqlcanvas:connected', { detail: { connected: true } }));
        } catch (error) { showError('[data-schema-error]', error); }
    }

    function showError(selector, error) { const box=document.querySelector(selector); box.textContent=error instanceof Error?error.message:'The operation failed.'; box.hidden=false; }
    function message(text) { const node=document.createElement('div'); node.className='grid-state'; node.textContent=text; return node; }

    document.querySelectorAll('[data-admin-view]').forEach((button) => button.addEventListener('click', () => { adminView=button.dataset.adminView; loadAdmin(); }));
    document.querySelector('[data-admin-refresh]').addEventListener('click', loadAdmin);
    document.querySelector('[data-canvas-refresh]').addEventListener('click', loadCanvas);
    document.querySelector('[data-canvas-export-json]').addEventListener('click', () => graphData && download(`${graphData.database}-schema.json`, JSON.stringify(graphData,null,2), 'application/json'));
    document.querySelector('[data-canvas-export-svg]').addEventListener('click', exportCanvasSvg);
    document.querySelector('[data-canvas-export-png]').addEventListener('click', exportCanvasPng);
    document.querySelector('[data-canvas-search]').addEventListener('input',filterCanvas);
    document.querySelector('[data-canvas-auto-layout]').addEventListener('click',autoLayout);
    document.querySelector('[data-canvas-zoom-out]').addEventListener('click',()=>zoomCanvas(-.1));
    document.querySelector('[data-canvas-zoom-in]').addEventListener('click',()=>zoomCanvas(.1));
    document.querySelector('[data-canvas-zoom-reset]').addEventListener('click',()=>{canvasScale=1;zoomCanvas(0);});
    document.querySelector('[data-canvas-compare]').addEventListener('click',()=>document.querySelector('[data-canvas-snapshot-file]').click());
    document.querySelector('[data-canvas-snapshot-file]').addEventListener('change',compareSnapshot);
    document.querySelectorAll('[data-canvas-diff-close]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-canvas-diff-dialog]').close()));
    document.querySelector('[data-database-export-sql]').addEventListener('click',exportDatabaseSql);
    document.querySelector('[data-database-import-sql]').addEventListener('click',openSqlImport);
    document.querySelector('[data-database-drop]').addEventListener('click',()=>openSchema('DROP_DATABASE'));
    document.querySelector('[data-database-copy]').addEventListener('click',()=>openSchema('COPY_DATABASE'));
    document.querySelector('[data-database-details]').addEventListener('click',showDatabaseDetails);
    document.querySelectorAll('[data-database-details-close]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-database-details-dialog]').close()));
    document.querySelector('[data-sql-import-file]').addEventListener('change',previewSqlImport);
    document.querySelector('[data-sql-import-form]').addEventListener('submit',importSql);
    document.querySelectorAll('[data-sql-import-close]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-sql-import-dialog]').close()));
    document.querySelector('[data-table-export]').addEventListener('click', () => fullExport('csv'));
    document.querySelector('[data-table-import]').addEventListener('click', openImport);
    document.querySelector('[data-import-file]').addEventListener('change', previewImport);
    document.querySelector('[data-import-form]').addEventListener('submit', importRows);
    document.querySelectorAll('[data-import-close]').forEach((button) => button.addEventListener('click', () => document.querySelector('[data-import-dialog]').close()));
    document.querySelector('[data-schema-create-database]')?.addEventListener('click', () => openSchema('CREATE_DATABASE'));
    document.querySelector('[data-schema-create-table]')?.addEventListener('click', () => openSchema('CREATE_TABLE'));
    document.querySelector('[data-table-schema-actions]').addEventListener('click', () => openSchema('TRUNCATE_TABLE'));
    document.querySelector('[data-schema-preview-button]').addEventListener('click', previewSchema);
    document.querySelector('[data-schema-form]').addEventListener('submit', applySchema);
    document.querySelectorAll('[data-schema-close]').forEach((button) => button.addEventListener('click', () => document.querySelector('[data-schema-dialog]').close()));
    document.querySelector('[data-admin-create-user]')?.addEventListener('click',()=>openAccount('create'));
    document.querySelector('[data-account-form]')?.addEventListener('submit',submitAccount);
    document.querySelectorAll('[data-account-close]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-account-dialog]').close()));
    document.querySelector('[data-privilege-form]')?.addEventListener('submit',submitPrivileges);
    document.querySelector('[data-privilege-form]')?.elements.action.addEventListener('change',updatePrivilegeConfirmation);
    document.querySelectorAll('[data-privilege-close]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-privilege-dialog]').close()));
    document.querySelector('[data-definition-copy]').addEventListener('click',async()=>{if(!selectedObject?.definition)return;try{await navigator.clipboard.writeText(selectedObject.definition);connection.activity('SQL copied',`${selectedObject.type} ${selectedObject.name}`);}catch{connection.activity('Copy unavailable','Select and copy the definition manually.');}});
    document.querySelector('[data-definition-query]').addEventListener('click',()=>{if(!selectedObject?.definition)return;document.querySelector('[data-query-editor]').value=selectedObject.definition;emitView('query');});
    document.querySelector('[data-definition-event-status]').addEventListener('click',changeEventStatus);
    document.querySelector('[data-definition-drop]').addEventListener('click',dropDefinition);
    document.addEventListener('sqlcanvas:disconnected', () => { selected={schema:null,table:null}; graphData=null; selectedObject=null; });
    return { loadCanvas, loadAdmin };
}

