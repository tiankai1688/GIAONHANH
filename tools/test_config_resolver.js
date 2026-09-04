// Quick functional test of the GN_CONFIG resolver priority.
function resolve(locationSearch, storage){
  const q = new URLSearchParams(locationSearch);
  const storedBase = (storage['gn_api_base'] || '').trim();
  const storedUse  = storage['gn_use_api']; // '1' | '0' | null
  let apiBase = (q.get('api') || '').trim() || storedBase;
  let useApi;
  if(q.has('live')) useApi = true;
  else if(q.has('demo')) useApi = false;
  else if(storedUse === '1') useApi = true;
  else if(storedUse === '0') useApi = false;
  else useApi = !!apiBase;
  apiBase = apiBase.replace(/\/+$/, '');
  return { apiBase, useApi };
}

const cases = [
  ['', {}, 'default offline demo'],
  ['?api=https://api.x.vn/', {}, 'url api -> live'],
  ['?api=https://api.x.vn///', {}, 'url api strips trailing slashes'],
  ['?demo', {gn_api_base:'https://api.x.vn', gn_use_api:'1'}, '?demo overrides stored live'],
  ['?live', {}, '?live forces live even w/o base'],
  ['', {gn_api_base:'https://api.x.vn', gn_use_api:'1'}, 'stored live'],
  ['', {gn_api_base:'https://api.x.vn', gn_use_api:'0'}, 'stored demo'],
  ['', {gn_api_base:''}, 'stored empty -> demo'],
];
let ok = true;
for(const [qs, st, label] of cases){
  const r = resolve(qs, st);
  console.log(label.padEnd(42), JSON.stringify(r));
}
console.log('ALL RESOLVER CASES RAN');
