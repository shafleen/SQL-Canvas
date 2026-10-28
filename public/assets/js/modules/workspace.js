const QUERY_HISTORY_KEY = 'sql-canvas.query-history.v1';

export function csvCell(value) {
    if (value === null || value === undefined) return '';
    let text = String(value);
    if (/^[=+\-@]/.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
}

export function formatSqlText(value) {
    let sql=String(value??'').trim();
    const keywords=['select','from','where','left join','right join','inner join','join','group by','order by','having','limit','insert into','values','update','set','delete from','create table','alter table','drop table'];
    keywords.forEach((keyword)=>{const pattern=new RegExp(`\\b${keyword.replace(' ','\\s+')}\\b`,'gi');sql=sql.replace(pattern,keyword.toUpperCase());});
    return sql.replace(/\s+(FROM|WHERE|LEFT JOIN|RIGHT JOIN|INNER JOIN|GROUP BY|ORDER BY|HAVING|LIMIT|VALUES|SET)\b/g,'\n$1');
}

export function initWorkspace(client, connection) {
    const welcome = document.querySelector('.welcome-canvas');
    const objectWorkspace = document.querySelector('[data-object-workspace]');
    const queryWorkspace = document.querySelector('[data-query-workspace]');
    const grid = document.querySelector('[data-data-grid]');
    const gridState = document.querySelector('[data-grid-state]');
    const structure = document.querySelector('[data-structure-surface]');
    const dataSurface = document.querySelector('[data-data-surface]');
    const recordDialog = document.querySelector('[data-record-dialog]');
    const recordForm = document.querySelector('[data-record-form]');
    const confirmDialog = document.querySelector('[data-confirm-dialog]');
    let current = null;
    let page = 1;
    let sort = '';
    let direction = 'ASC';
    let editingRow = null;
    let filterColumn = '';
    let filterValue = '';
    let lastQueryResult = null;
    let queryTabs = [];
    let activeQueryId = null;
    let nextQueryId = 1;
    const selectedRows = new Map();
    let cursor = null;
    let cursorStack = [];

    function show(view) {
        welcome.hidden = view !== 'home';
        objectWorkspace.hidden = view !== 'object';
        queryWorkspace.hidden = view !== 'query';
        document.querySelector('[data-definition-workspace]').hidden = view !== 'definition';
        document.querySelector('[data-canvas-workspace]').hidden = view !== 'canvas';
        document.querySelector('[data-admin-workspace]').hidden = view !== 'admin';
        document.querySelectorAll('[data-global-view]').forEach((button) => button.setAttribute('aria-selected', String(button.dataset.globalView === view || (view === 'object' && button.dataset.globalView === 'home'))));
    }

    async function openTable({ schema, table }) {
        current = { schema, table };
        page = 1;
        sort = '';
        direction = 'ASC';
        cursor=null;cursorStack=[];
        filterColumn = '';
        filterValue = '';
        show('object');
        document.querySelector('[data-object-schema]').textContent = schema;
        document.querySelector('[data-object-table]').textContent = table;
        document.querySelector('[data-selected-database]').textContent = schema;
        document.querySelector('[data-selected-object]').textContent = table;
        await Promise.all([loadRows(), loadStructure()]);
    }

    async function loadRows() {
        if (!current) return;
        grid.hidden = true;
        gridState.hidden = false;
        gridState.textContent = 'Loading rows…';
        try {
            const path = `/databases/${encodeURIComponent(current.schema)}/tables/${encodeURIComponent(current.table)}/rows`;
            const data = await client.get(path, { page, pageSize: 50, sort, direction, filterColumn, filterValue, cursor });
            current = { ...current, ...data };
            selectedRows.clear();updateSelectionActions();
            sort = data.sort;
            const filterSelect=document.querySelector('[data-grid-filter-column]');
            const prior=filterSelect.value;filterSelect.replaceChildren(new Option('No filter',''),...data.columns.map((column)=>new Option(column.name,column.name)));filterSelect.value=filterColumn||prior;
            renderGrid(data);
            document.querySelector('[data-row-add]').hidden = !data.writable;
            document.querySelector('[data-selected-rows]').textContent = `${data.rows.length} on page ${data.page}`;
            document.querySelector('[data-selected-identity]').textContent = data.primaryKey.length ? data.primaryKey.join(', ') : 'Read only · no primary key';
            connection.activity('Rows loaded', `${current.schema}.${current.table} · page ${data.page}`);
        } catch (error) {
            gridState.textContent = error instanceof Error ? error.message : 'Rows could not be loaded.';
        }
    }

    function renderGrid(data) {
        grid.replaceChildren();
        const head = document.createElement('thead');
        const headerRow = document.createElement('tr');
        const selectHead=document.createElement('th');const selectAll=document.createElement('input');selectAll.type='checkbox';selectAll.setAttribute('aria-label','Select all rows on this page');selectAll.addEventListener('change',()=>{body.querySelectorAll('[data-row-select]').forEach((box)=>{box.checked=selectAll.checked;box.dispatchEvent(new Event('change'));});});selectHead.append(selectAll);headerRow.append(selectHead);
        data.columns.forEach((column) => {
            const th = document.createElement('th');
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = `${column.name}${sort === column.name ? (direction === 'ASC' ? ' ↑' : ' ↓') : ''}`;
            button.title = `${column.type} · click to sort`;
            button.addEventListener('click', () => {
                direction = sort === column.name && direction === 'ASC' ? 'DESC' : 'ASC';
                sort = column.name;
                page = 1;
                cursor=null;cursorStack=[];
                loadRows();
            });
            th.append(button);
            headerRow.append(th);
        });
        head.append(headerRow);
        const body = document.createElement('tbody');
        data.rows.forEach((row) => {
            const tr = document.createElement('tr');
            if (data.writable) tr.classList.add('is-editable');
            const selectCell=document.createElement('td');const select=document.createElement('input');select.type='checkbox';select.dataset.rowSelect='';select.setAttribute('aria-label','Select row');const identity=Object.fromEntries(data.primaryKey.map((key)=>[key,row[key]]));const rowKey=JSON.stringify(identity);select.disabled=data.primaryKey.length===0;select.addEventListener('change',()=>{if(select.checked)selectedRows.set(rowKey,row);else selectedRows.delete(rowKey);updateSelectionActions();});selectCell.append(select);tr.append(selectCell);
            data.columns.forEach((column) => {
                const td = document.createElement('td');
                const value = row[column.name];
                td.textContent = value === null ? 'NULL' : displayValue(value);
                if (value === null) td.classList.add('null-value');
                td.title = td.textContent;
                if (data.writable && !/binary|blob/i.test(column.type) && !/generated/i.test(column.extra)) td.addEventListener('dblclick', (event) => { event.stopPropagation(); beginInlineEdit(td, row, column); });
                tr.append(td);
            });
            if (data.writable) { tr.tabIndex=0; tr.addEventListener('keydown',(event)=>{if(event.key==='Enter' && event.target===tr)openRecord('edit',row);}); }
            body.append(tr);
        });
        grid.append(head, body);
        grid.hidden = false;
        gridState.hidden = true;
        document.querySelector('[data-grid-summary]').textContent = `${data.rows.length} rows · page ${data.page}${data.editable ? '' : ' · read only: no primary key'}`;
        document.querySelector('[data-page-previous]').disabled = data.page <= 1;
        document.querySelector('[data-page-next]').disabled = !data.hasMore;
    }

    function displayValue(value) {
        if (value?.__sqlCanvasBinary === true) return `Binary · ${value.bytes} bytes`;
        if (typeof value === 'object') return JSON.stringify(value);
        return String(value);
    }

    function updateSelectionActions(){const count=selectedRows.size;document.querySelector('[data-selection-copy-csv]').hidden=count===0;document.querySelector('[data-selection-copy-json]').hidden=count===0;document.querySelector('[data-selection-copy-sql]').hidden=count===0;document.querySelector('[data-selection-delete]').hidden=count===0||!current?.writable;}

    async function copySelected(format){const rows=[...selectedRows.values()];if(!rows.length)return;const columns=current.columns.map((column)=>column.name);let text;if(format==='json')text=JSON.stringify(rows,null,2);else if(format==='csv')text=[columns.map(csvCell).join(','),...rows.map((row)=>columns.map((column)=>csvCell(displayValue(row[column]))).join(','))].join('\r\n');else{text=rows.map((row)=>`INSERT INTO \`${String(current.schema).replaceAll('`','``')}\`.\`${String(current.table).replaceAll('`','``')}\` (${columns.map((column)=>`\`${column.replaceAll('`','``')}\``).join(', ')}) VALUES (${columns.map((column)=>sqlLiteral(row[column])).join(', ')});`).join('\n');}try{await navigator.clipboard.writeText(text);connection.activity('Rows copied',`${rows.length} selected · ${format.toUpperCase()}`);}catch{connection.activity('Copy unavailable','Clipboard access was denied by the browser.');}}

    function sqlLiteral(value){if(value===null||value===undefined)return'NULL';if(value?.__sqlCanvasBinary===true)return`FROM_BASE64('${value.base64}')`;if(typeof value==='number')return String(value);return`'${String(value).replaceAll("'","''")}'`;}

    function requestBulkDelete(){const count=selectedRows.size;if(!count)return;const target=`${current.schema}.${current.table}:${count}`;document.querySelector('[data-confirm-title]').textContent=`Delete ${count} selected rows?`;document.querySelector('[data-confirm-message]').textContent='All selected rows are identified by their primary keys. The operation is transactional and stops if any row changed or disappeared.';document.querySelector('[data-confirm-label]').textContent=`Type ${target} to confirm`;document.querySelector('[data-confirm-input]').value='';document.querySelector('[data-confirm-error]').hidden=true;confirmDialog.dataset.action='bulk-delete';confirmDialog.showModal();}

    function beginInlineEdit(cell,row,column) {
        if(cell.classList.contains('is-editing'))return;const original=row[column.name];const input=document.createElement('input');input.value=original===null?'':displayValue(original);cell.classList.add('is-editing');cell.replaceChildren(input);input.focus();input.select();let finished=false;
        const cancel=()=>{if(finished)return;finished=true;cell.classList.remove('is-editing');cell.textContent=original===null?'NULL':displayValue(original);if(original===null)cell.classList.add('null-value');};
        const save=async()=>{if(finished)return;finished=true;const value=input.value;cell.textContent='Saving…';try{const identity=Object.fromEntries(current.primaryKey.map((key)=>[key,row[key]]));await client.post(`/databases/${encodeURIComponent(current.schema)}/tables/${encodeURIComponent(current.table)}/rows/update`,{identity,changes:{[column.name]:value},original:row});row[column.name]=value;cell.classList.remove('is-editing','null-value');cell.textContent=displayValue(value);connection.activity('Cell updated',`${current.table}.${column.name}`);}catch(error){finished=false;cell.classList.remove('is-editing');cell.textContent=original===null?'NULL':displayValue(original);connection.activity('Update failed',error instanceof Error?error.message:'The cell was not updated.');}};
        input.addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();save();}else if(event.key==='Escape'){event.preventDefault();cancel();}});input.addEventListener('blur',cancel,{once:true});
    }

    async function loadStructure() {
        if (!current) return;
        try {
            const data = await client.get(`/databases/${encodeURIComponent(current.schema)}/tables/${encodeURIComponent(current.table)}`);
            structure.replaceChildren(
                structureGroup('Table information', [data.information], (item) => [item.engine || 'View', `${Number(item.estimatedRows || 0).toLocaleString()} estimated rows`, `${Number(item.dataBytes || 0).toLocaleString()} data bytes`, `${Number(item.indexBytes || 0).toLocaleString()} index bytes`, item.collation || '—', item.autoIncrement ? `Next ID ${item.autoIncrement}` : '—']),
                structureGroup('Columns', data.columns, (item) => [item.name, item.type, item.nullable === 'YES' ? 'NULL' : 'Required', item.extra || item.columnKey || '—']),
                structureGroup('Indexes', data.indexes, (item) => [item.name, item.columnName, item.nonUnique ? 'Non-unique' : 'Unique', item.type]),
                structureGroup('Foreign keys', data.foreignKeys, (item) => [item.name, item.columnName, '→', `${item.referencedDatabase}.${item.referencedTable}.${item.referencedColumn}`]),
            );
        } catch (error) {
            structure.replaceChildren(message(error instanceof Error ? error.message : 'Structure could not be loaded.'));
        }
    }

    function structureGroup(title, items, values) {
        const group = document.createElement('section');
        const heading = document.createElement('h3');
        heading.textContent = title;
        group.className = 'structure-group';
        group.append(heading);
        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'structure-row';
            values(item).forEach((value, index) => {
                const cell = index === 1 ? document.createElement('code') : document.createElement('span');
                cell.textContent = String(value ?? '—');
                row.append(cell);
            });
            group.append(row);
        });
        return group;
    }

    function message(text) {
        const node = document.createElement('div');
        node.className = 'grid-state';
        node.textContent = text;
        return node;
    }

    function openRecord(mode, row = {}) {
        editingRow = mode === 'edit' ? row : null;
        document.querySelector('[data-record-mode]').textContent = mode === 'edit' ? 'Edit record' : 'New record';
        document.querySelector('[data-record-title]').textContent = mode === 'edit' ? `Edit ${current.table} row` : `Add row to ${current.table}`;
        document.querySelector('[data-record-save]').textContent = mode === 'edit' ? 'Save changes' : 'Insert row';
        document.querySelector('[data-row-delete]').hidden = mode !== 'edit';
        document.querySelector('[data-row-duplicate]').hidden = mode !== 'edit';
        document.querySelector('[data-row-copy]').hidden = mode !== 'edit';
        document.querySelector('[data-record-error]').hidden = true;
        const fields = document.querySelector('[data-record-fields]');
        fields.replaceChildren();
        current.columns.forEach((column) => {
            if (mode === 'new' && String(column.extra).includes('auto_increment')) return;
            if (/generated/i.test(String(column.extra))) return;
            const wrapper = document.createElement('label');
            const label = document.createElement('span');
            const name = document.createElement('strong');
            const type = document.createElement('code');
            const normalizedType=String(column.type).toLowerCase();
            const enumValues=[...normalizedType.matchAll(/'((?:''|[^'])*)'/g)].map((match)=>match[1].replaceAll("''","'"));
            let input;
            if(normalizedType.startsWith('enum(')){input=document.createElement('select');enumValues.forEach((optionValue)=>input.append(new Option(optionValue,optionValue)));}
            else if(/tinyint\(1\)|boolean|bool/.test(normalizedType)){input=document.createElement('select');input.append(new Option('False · 0','0'),new Option('True · 1','1'));}
            else if(/text|json|blob|binary/.test(normalizedType))input=document.createElement('textarea');
            else input=document.createElement('input');
            const nullable = document.createElement('label');
            const nullBox = document.createElement('input');
            name.textContent = column.name;
            type.textContent = column.type;
            label.append(name, type);
            wrapper.className = 'record-field';
            input.name = column.name;
            if (input instanceof HTMLInputElement) {
                if(/^date$/.test(normalizedType))input.type='date';
                else if(/datetime|timestamp/.test(normalizedType))input.type='datetime-local';
                else if(/^time/.test(normalizedType))input.type='time';
                else if(/int|decimal|float|double|numeric/.test(normalizedType)){input.type='number';input.step='any';}
                else input.type='text';
            }
            const value = row[column.name];
            input.value = value === null || value === undefined ? '' : (/datetime|timestamp/.test(normalizedType)?String(value).replace(' ','T'):displayValue(value));
            input.dataset.sqlType=normalizedType;
            if(value?.__sqlCanvasBinary===true){input.value=value.base64;input.disabled=true;input.dataset.readOnly='true';const save=document.createElement('button');save.type='button';save.className='secondary-button';save.textContent=`Download ${value.bytes} bytes`;save.addEventListener('click',()=>downloadBinary(column.name,value));wrapper.append(label,input,save);fields.append(wrapper);return;}
            nullBox.type = 'checkbox';
            nullBox.checked = value === null;
            nullBox.disabled = column.nullable !== 'YES';
            nullBox.dataset.nullFor = column.name;
            nullable.className = 'null-toggle';
            nullable.append(nullBox, document.createTextNode('NULL'));
            nullBox.addEventListener('change', () => { input.disabled = nullBox.checked; });
            input.disabled = nullBox.checked;
            wrapper.append(label, input, nullable);
            fields.append(wrapper);
        });
        recordDialog.showModal();
    }

    function collectRecordValues() {
        const values = {};
        current.columns.forEach((column) => {
            const input = recordForm.elements.namedItem(column.name);
            if (!input) return;
            if(input.dataset.readOnly==='true')return;
            const nullBox = recordForm.querySelector(`[data-null-for="${CSS.escape(column.name)}"]`);
            let value=input.value;if(/datetime|timestamp/.test(input.dataset.sqlType??''))value=value.replace('T',' ');if((input.dataset.sqlType??'').startsWith('json')&&value!==''){try{value=JSON.stringify(JSON.parse(value));}catch{throw new Error(`${column.name} must contain valid JSON.`);}}
            values[column.name] = nullBox?.checked ? null : value;
        });
        return values;
    }

    function downloadBinary(column,value){const bytes=Uint8Array.from(atob(value.base64),(char)=>char.charCodeAt(0));const url=URL.createObjectURL(new Blob([bytes]));const link=document.createElement('a');link.href=url;link.download=`${current.table}-${column}.bin`;link.click();URL.revokeObjectURL(url);}

    async function saveRecord(event) {
        event.preventDefault();
        const errorBox = document.querySelector('[data-record-error]');
        try {
            const base = `/databases/${encodeURIComponent(current.schema)}/tables/${encodeURIComponent(current.table)}/rows`;
            if (editingRow) {
                const identity = Object.fromEntries(current.primaryKey.map((key) => [key, editingRow[key]]));
                await client.post(`${base}/update`, { identity, changes: collectRecordValues(), original: editingRow });
                connection.activity('Row updated', `${current.schema}.${current.table}`);
            } else {
                await client.post(`${base}/insert`, { values: collectRecordValues() });
                connection.activity('Row inserted', `${current.schema}.${current.table}`);
            }
            recordDialog.close();
            await loadRows();
        } catch (error) {
            errorBox.textContent = error instanceof Error ? error.message : 'The row could not be saved.';
            errorBox.hidden = false;
        }
    }

    function requestDelete() {
        const target = `${current.schema}.${current.table}`;
        document.querySelector('[data-confirm-title]').textContent = 'Delete this row?';
        document.querySelector('[data-confirm-message]').textContent = 'This permanently deletes one row selected by its primary key. There is no fake undo.';
        document.querySelector('[data-confirm-label]').textContent = `Type ${target} to confirm`;
        document.querySelector('[data-confirm-input]').value = '';
        document.querySelector('[data-confirm-error]').hidden = true;
        confirmDialog.dataset.action = 'delete-row';
        confirmDialog.showModal();
    }

    async function confirmAction(event) {
        event.preventDefault();
        const bulk=confirmDialog.dataset.action==='bulk-delete';if(!bulk&&(confirmDialog.dataset.action!=='delete-row'||!editingRow))return;
        const target = bulk?`${current.schema}.${current.table}:${selectedRows.size}`:`${current.schema}.${current.table}`;
        const entered = document.querySelector('[data-confirm-input]').value;
        const error = document.querySelector('[data-confirm-error]');
        if (entered !== target) {
            error.textContent = 'The confirmation text does not match the qualified table name.';
            error.hidden = false;
            return;
        }
        try {
            const base = `/databases/${encodeURIComponent(current.schema)}/tables/${encodeURIComponent(current.table)}/rows`;
            if(bulk){const identities=[...selectedRows.values()].map((row)=>Object.fromEntries(current.primaryKey.map((key)=>[key,row[key]])));await client.post(`${base}/bulk-delete`,{identities,confirmation:target});}
            else {const identity = Object.fromEntries(current.primaryKey.map((key) => [key, editingRow[key]]));await client.post(`${base}/delete`, { identity, confirmation: target });}
            confirmDialog.close();
            if(!bulk)recordDialog.close();
            connection.activity(bulk?'Rows deleted':'Row deleted', target);
            await loadRows();
        } catch (reason) {
            error.textContent = reason instanceof Error ? reason.message : 'The row could not be deleted.';
            error.hidden = false;
        }
    }

    function exportPage() {
        if (!current?.rows?.length) return;
        const columns = current.columns.map((column) => column.name);
        const csv = [columns.map(csvCell).join(','), ...current.rows.map((row) => columns.map((column) => csvCell(row[column])).join(','))].join('\r\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `${current.schema}-${current.table}-page-${current.page}.csv`;
        link.click();
        URL.revokeObjectURL(url);
    }

    function createQueryTab(sql = '') {
        const tab={id:nextQueryId++,name:`Query ${nextQueryId-1}`,sql};queryTabs.push(tab);activeQueryId=tab.id;renderQueryTabs();document.querySelector('[data-query-editor]').value=sql;return tab;
    }

    function activeQuery() { return queryTabs.find((tab)=>tab.id===activeQueryId)??null; }

    function renderQueryTabs() {
        const container=document.querySelector('[data-query-tabs]');container.replaceChildren();
        queryTabs.forEach((tab)=>{const wrapper=document.createElement('span');wrapper.className='query-tab-wrap';const select=document.createElement('button');select.type='button';select.className='query-tab';select.setAttribute('role','tab');select.setAttribute('aria-selected',String(tab.id===activeQueryId));select.textContent=tab.name;select.addEventListener('click',()=>{const currentTab=activeQuery();if(currentTab)currentTab.sql=document.querySelector('[data-query-editor]').value;activeQueryId=tab.id;document.querySelector('[data-query-editor]').value=tab.sql;renderQueryTabs();});const close=document.createElement('button');close.type='button';close.className='query-tab-close';close.textContent='×';close.setAttribute('aria-label',`Close ${tab.name}`);close.addEventListener('click',()=>closeQueryTab(tab.id));wrapper.append(select,close);container.append(wrapper);});
    }

    function closeQueryTab(id) {const index=queryTabs.findIndex((tab)=>tab.id===id);if(index<0)return;queryTabs.splice(index,1);if(activeQueryId===id){const next=queryTabs[Math.max(0,index-1)]??createQueryTab();activeQueryId=next.id;document.querySelector('[data-query-editor]').value=next.sql;}renderQueryTabs();}

    function openQuery(newDocument = false) {
        if(newDocument||queryTabs.length===0)createQueryTab();
        show('query');
        document.querySelector('[data-query-editor]').focus();
    }

    async function executeQuery(explain = false) {
        const editor=document.querySelector('[data-query-editor]');
        const sql = editor.selectionStart!==editor.selectionEnd?editor.value.slice(editor.selectionStart,editor.selectionEnd):editor.value;
        const result = document.querySelector('[data-query-result]');
        result.replaceChildren(message(explain ? 'Building execution plan…' : 'Running query…'));
        try {
            const data = await client.post(explain ? '/query/explain' : '/query/execute', { sql });
            renderQueryResult(data);
            saveQueryHistory(sql);
            connection.activity(explain ? 'Plan loaded' : 'Query completed', `${data.durationMs} ms · ${data.rows.length} rows`);
        } catch (error) {
            if(!explain&&error?.code==='confirmation_required'){
                const confirmation=window.prompt('This statement may change data, schema, accounts, or privileges. Review it carefully, then type EXECUTE to run it.','');
                if(confirmation==='EXECUTE'){try{const data=await client.post('/query/execute',{sql,confirmation});renderQueryResult(data);saveQueryHistory(sql);connection.activity('Query completed',`${data.durationMs} ms · ${data.affectedRows??data.rows.length} affected or returned`);return;}catch(retryError){error=retryError;}}
            }
            result.replaceChildren(message(error instanceof Error ? error.message : 'The query could not be completed.'));
            connection.activity('Query failed', error instanceof Error ? error.message : 'Database error');
        }
    }

    function renderQueryResult(data) {
        lastQueryResult=data;document.querySelector('[data-query-export]').disabled=!data.columns.length;
        const result = document.querySelector('[data-query-result]');
        result.replaceChildren();
        const meta = document.createElement('div');
        meta.className = 'query-meta';
        meta.textContent = `${data.classification} · ${data.durationMs} ms · ${data.rows.length} rows${data.truncated ? ' · truncated' : ''}${data.affectedRows !== null ? ` · ${data.affectedRows} affected` : ''}`;
        result.append(meta);
        if (!data.columns.length) return;
        const table = document.createElement('table');
        table.className = 'data-grid';
        const head = document.createElement('thead');
        const tr = document.createElement('tr');
        data.columns.forEach((column) => { const th = document.createElement('th'); th.textContent = column.name; tr.append(th); });
        head.append(tr);
        const body = document.createElement('tbody');
        data.rows.forEach((row) => { const line = document.createElement('tr'); data.columns.forEach((column) => { const td = document.createElement('td'); td.textContent = row[column.name] === null ? 'NULL' : displayValue(row[column.name]); line.append(td); }); body.append(line); });
        table.append(head, body);
        result.append(table);
    }

    function saveQueryHistory(sql) {
        if (!document.querySelector('[data-query-history-optin]').checked || !sql.trim()) return;
        const history = JSON.parse(localStorage.getItem(QUERY_HISTORY_KEY) ?? '[]');
        localStorage.setItem(QUERY_HISTORY_KEY, JSON.stringify([sql, ...history.filter((item) => item !== sql)].slice(0, 20)));
    }

    function insertQueryText(text) {const editor=document.querySelector('[data-query-editor]');const start=editor.selectionStart,end=editor.selectionEnd;editor.setRangeText(text,start,end,'end');editor.dispatchEvent(new Event('input'));editor.focus();}

    function formatQuery() {const editor=document.querySelector('[data-query-editor]');editor.value=formatSqlText(editor.value);editor.dispatchEvent(new Event('input'));}

    function showSuggestions() {const dialog=document.querySelector('[data-query-suggestions-dialog]');const list=document.querySelector('[data-query-suggestions]');list.replaceChildren();const values=[];if(current?.schema&&current?.table){values.push({label:`${current.schema}.${current.table}`,value:`\`${current.schema}\`.\`${current.table}\``});current.columns?.forEach((column)=>values.push({label:`${current.table}.${column.name}`,value:`\`${column.name}\``}));}if(!values.length){const empty=message('Select a table in the explorer to load permitted metadata suggestions.');list.append(empty);}values.forEach((item)=>{const button=document.createElement('button');button.type='button';button.textContent=item.label;button.addEventListener('click',()=>{insertQueryText(item.value);dialog.close();});list.append(button);});dialog.showModal();}

    function findNext() {const editor=document.querySelector('[data-query-editor]');const needle=document.querySelector('[data-query-find-text]').value;if(!needle)return;let index=editor.value.indexOf(needle,editor.selectionEnd);if(index<0)index=editor.value.indexOf(needle);if(index>=0){editor.focus();editor.setSelectionRange(index,index+needle.length);}}

    document.addEventListener('sqlcanvas:table-selected', (event) => openTable(event.detail));
    document.addEventListener('sqlcanvas:view', (event) => show(event.detail.view));
    document.addEventListener('sqlcanvas:disconnected', () => { current = null; show('home'); });
    document.querySelectorAll('[data-new-query]').forEach((button) => button.addEventListener('click', () => openQuery(true)));
    document.querySelector('[data-grid-refresh]').addEventListener('click', loadRows);
    document.querySelector('[data-grid-export]').addEventListener('click', exportPage);
    document.querySelector('[data-selection-copy-csv]').addEventListener('click',()=>copySelected('csv'));
    document.querySelector('[data-selection-copy-json]').addEventListener('click',()=>copySelected('json'));
    document.querySelector('[data-selection-copy-sql]').addEventListener('click',()=>copySelected('sql'));
    document.querySelector('[data-selection-delete]').addEventListener('click',requestBulkDelete);
    document.querySelector('[data-grid-filter-apply]').addEventListener('click',()=>{filterColumn=document.querySelector('[data-grid-filter-column]').value;filterValue=document.querySelector('[data-grid-filter-value]').value;page=1;cursor=null;cursorStack=[];loadRows();});
    document.querySelector('[data-grid-filter-clear]').addEventListener('click',()=>{filterColumn='';filterValue='';document.querySelector('[data-grid-filter-column]').value='';document.querySelector('[data-grid-filter-value]').value='';page=1;cursor=null;cursorStack=[];loadRows();});
    document.querySelector('[data-grid-filter-value]').addEventListener('keydown',(event)=>{if(event.key==='Enter')document.querySelector('[data-grid-filter-apply]').click();});
    document.querySelector('[data-row-add]').addEventListener('click', () => openRecord('new'));
    document.querySelector('[data-page-previous]').addEventListener('click', () => { if(current?.paginationMode==='keyset'){cursor=cursorStack.pop()??null;}page=Math.max(1,page-1);loadRows(); });
    document.querySelector('[data-page-next]').addEventListener('click', () => { if(current?.paginationMode==='keyset'){cursorStack.push(cursor);cursor=current.nextCursor;}page+=1;loadRows(); });
    document.querySelectorAll('[data-object-view]').forEach((button) => button.addEventListener('click', () => {
        const isData = button.dataset.objectView === 'data';
        dataSurface.hidden = !isData;
        structure.hidden = isData;
        document.querySelectorAll('[data-object-view]').forEach((item) => item.setAttribute('aria-selected', String(item === button)));
    }));
    document.querySelectorAll('[data-record-close]').forEach((button) => button.addEventListener('click', () => recordDialog.close()));
    recordForm.addEventListener('submit', saveRecord);
    document.querySelector('[data-row-delete]').addEventListener('click', requestDelete);
    document.querySelector('[data-row-duplicate]').addEventListener('click',async()=>{if(!editingRow)return;const identity=Object.fromEntries(current.primaryKey.map((key)=>[key,editingRow[key]]));try{const data=await client.post(`/databases/${encodeURIComponent(current.schema)}/tables/${encodeURIComponent(current.table)}/rows/duplicate`,{identity});recordDialog.close();connection.activity('Row duplicated',data.insertId?`New ID ${data.insertId}`:'One row inserted');loadRows();}catch(error){const box=document.querySelector('[data-record-error]');box.textContent=error instanceof Error?error.message:'The row could not be duplicated.';box.hidden=false;}});
    document.querySelector('[data-row-copy]').addEventListener('click',async()=>{if(!editingRow)return;try{await navigator.clipboard.writeText(JSON.stringify(editingRow,null,2));connection.activity('Row copied','JSON copied to clipboard');}catch{connection.activity('Copy unavailable','Copy the row values manually.');}});
    document.querySelector('[data-confirm-form]').addEventListener('submit', confirmAction);
    document.querySelector('[data-confirm-cancel]').addEventListener('click', () => confirmDialog.close());
    document.querySelector('[data-query-run]').addEventListener('click', () => executeQuery(false));
    document.querySelector('[data-query-explain]').addEventListener('click', () => executeQuery(true));
    document.querySelector('[data-query-clear-history]').addEventListener('click', () => localStorage.removeItem(QUERY_HISTORY_KEY));
    document.querySelector('[data-query-export]').addEventListener('click',()=>{if(!lastQueryResult?.columns.length)return;const columns=lastQueryResult.columns.map((column)=>column.name);const csv=[columns.map(csvCell).join(','),...lastQueryResult.rows.map((row)=>columns.map((column)=>csvCell(row[column])).join(','))].join('\r\n');const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));const link=document.createElement('a');link.href=url;link.download='sql-canvas-query-results.csv';link.click();URL.revokeObjectURL(url);});

    document.querySelector('[data-query-add-tab]').addEventListener('click',()=>openQuery(true));
    document.querySelector('[data-query-editor]').addEventListener('input',(event)=>{const tab=activeQuery();if(tab){tab.sql=event.currentTarget.value;const first=tab.sql.trim().split(/\s+/).slice(0,2).join(' ');tab.name=first?first.slice(0,24):`Query ${tab.id}`;renderQueryTabs();}});
    document.querySelector('[data-query-snippet]').addEventListener('change',(event)=>{if(event.currentTarget.value)insertQueryText(event.currentTarget.value);event.currentTarget.value='';});
    document.querySelector('[data-query-format]').addEventListener('click',formatQuery);
    document.querySelector('[data-query-complete]').addEventListener('click',showSuggestions);
    document.querySelector('[data-query-find]').addEventListener('click',()=>{const bar=document.querySelector('[data-query-findbar]');bar.hidden=!bar.hidden;if(!bar.hidden)document.querySelector('[data-query-find-text]').focus();});
    document.querySelector('[data-query-find-next]').addEventListener('click',findNext);
    document.querySelector('[data-query-replace-one]').addEventListener('click',()=>{const editor=document.querySelector('[data-query-editor]');const needle=document.querySelector('[data-query-find-text]').value;if(editor.value.slice(editor.selectionStart,editor.selectionEnd)===needle){editor.setRangeText(document.querySelector('[data-query-replace-text]').value,editor.selectionStart,editor.selectionEnd,'end');editor.dispatchEvent(new Event('input'));}findNext();});
    document.querySelector('[data-query-replace-all]').addEventListener('click',()=>{const editor=document.querySelector('[data-query-editor]');const needle=document.querySelector('[data-query-find-text]').value;if(!needle)return;editor.value=editor.value.split(needle).join(document.querySelector('[data-query-replace-text]').value);editor.dispatchEvent(new Event('input'));});
    document.querySelectorAll('[data-query-suggestions-close]').forEach((button)=>button.addEventListener('click',()=>document.querySelector('[data-query-suggestions-dialog]').close()));

    createQueryTab();

    return { openTable, openQuery, loadRows };
}

