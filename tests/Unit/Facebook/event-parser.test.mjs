import test from 'node:test';
import assert from 'node:assert/strict';
import { collectEventNodes, parseEventNodes, facebookEventId } from '../../../scripts/facebook/event-parser.mjs';
const id = '1078756118449684';
const header = { id, name: 'Evento richiesto', start_timestamp: 1792177200, cover_media_renderer: { cover_photo: { photo: { full_image: {uri: 'https://scontent.xx.fbcdn.net/photo.jpg', width:1200, height:628} } } } };
const details = { id, event_description: {text:'Descrizione completa\nIngresso €15',ranges:[]}, event_place:{name:'Locale',location:{latitude:45,longitude:11}} };

test('accepts canonical, tracking and named event URLs', () => {
    for(const url of [`https://www.facebook.com/events/${id}/?locale=it_IT`, `https://facebook.com/events/locale/titolo/${id}/`]) assert.equal(facebookEventId(url),id);
});
test('rejects arbitrary hosts, credentials, ports, non-event pages and schemes', () => {
    for(const url of ['http://facebook.com/events/123/','https://facebook.com.evil.test/events/123/','https://user@facebook.com/events/123/','https://facebook.com:8443/events/123/','https://facebook.com/csopedro','file:///etc/passwd']) assert.throws(()=>facebookEventId(url));
});
test('merges fragments only for the requested event, preserving full text, photo and exact timestamp', () => {
    const nodes=collectEventNodes([JSON.stringify({data:[details, {id:'99',name:'Suggerito',start_timestamp:123},header]}),'invalid'],id);
    const event=parseEventNodes(nodes,id);
    assert.equal(event.title,'Evento richiesto');
    assert.equal(event.description,details.event_description.text);
    assert.equal(event.starts_at,'2026-10-16T19:00:00.000Z');
    assert.equal(event.ends_at,null);
    assert.equal(event.cover.width,1200);
    assert.equal(event.venue.latitude,45);
    assert.equal(event.responded_count,null);
});
test('fails on preview-only or wrong-event data instead of importing recommendations',()=>{
    assert.throws(()=>parseEventNodes([header],id));
    assert.throws(()=>parseEventNodes([{...header,id:'99'},{...details,id:'99'}],id));
});
test('preserves explicit end time, online/cancelled state and keeps Facebook counts separate',()=>{
    const event=parseEventNodes([header,details,{id,end_timestamp:1792184400,is_canceled:true,is_online:true,event_connected_users_public_responded:{count:35}}],id);
    assert.equal(event.ends_at,'2026-10-16T21:00:00.000Z');
    assert.equal(event.responded_count,35);
    assert.equal(event.is_cancelled,true);
    assert.equal(event.is_online,true);
});
test('ignores prototype keys in external JSON',()=>{
    const event=parseEventNodes([header, details,JSON.parse(`{"id":"${id}","__proto__":{"polluted":true}}`)],id);
    assert.equal({}.polluted,undefined);
    assert.equal(event.title,'Evento richiesto');
});

test('imports the full description when dates and cover are absent',()=>{
    const event=parseEventNodes([{...details,name:"Titolo"}],id);
    assert.equal(event.description,details.event_description.text);
    assert.equal(event.title,"Titolo");
    assert.equal(event.starts_at,null);
    assert.equal(event.ends_at,null);
    assert.equal(event.cover,null);
});

test("rejects a missing title even with a full description",()=>{assert.throws(()=>parseEventNodes([details],id));});

test('keeps all organizers from both Facebook host collections without duplicates',()=>{
    const event=parseEventNodes([header,details,{id,event_hosts_that_can_view_guestlist:[{id:'a',name:'Primo'}],parent_if_exists_or_self:{event_accepted_cohosts:{nodes:[{id:'a',name:'Primo'},{id:'b',name:'Secondo',url:'https://www.facebook.com/secondo'}]}}}],id);
    assert.deepEqual(event.hosts.map(host=>host.name),['Primo','Secondo']);
});
