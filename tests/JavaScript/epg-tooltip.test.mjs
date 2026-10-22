import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/vendor/epg-viewer.js', import.meta.url), 'utf8');

function viewer() {
    const context = vm.createContext({
        window: { innerWidth: 1200 },
        localStorage: { getItem: () => null },
    });
    vm.runInContext(source, context);
    const component = context.window.epgViewer({ apiUrl: '/unused' });
    component.formatProgrammeTime = () => '12:00 - 13:00';
    return component;
}

test('dynamic tooltip fields are encoded as text, preserving the layout', () => {
    const content = viewer().getTooltipContent({
        title: '<b>News & Culture</b> "today"',
        desc: "A <em>literal</em> tag & someone's café 日本語",
        category: '<i>Arts</i> & culture',
        new: true,
        episode_num: '0.1.',
    });

    assert.ok(content.includes('<p><strong>&lt;b&gt;News &amp; Culture&lt;/b&gt; &quot;today&quot;</strong></p>'));
    assert.ok(content.includes('<p>A &lt;em&gt;literal&lt;/em&gt; tag &amp; someone&#039;s café 日本語</p>'));
    assert.ok(content.includes('<small>Category: &lt;i&gt;Arts&lt;/i&gt; &amp; culture</small>'));
    assert.ok(content.includes('<small>12:00 - 13:00</small><br/>'));
    assert.ok(content.includes('<small>New Episode</small>'));
    assert.ok(content.includes('<small>Season 1, Episode 2</small>'));
});

test('missing and empty optional fields do not add undefined or empty paragraphs', () => {
    for (const programme of [{}, { title: null, desc: '', category: '   ' }]) {
        const content = viewer().getTooltipContent(programme);
        assert.equal(content, '<p><strong></strong></p><small>12:00 - 13:00</small><br/>');
    }
});

test('entity-like provider text is encoded once instead of being decoded as markup', () => {
    assert.ok(viewer().getTooltipContent({ title: '&lt;b&gt;literal&lt;/b&gt;' })
        .includes('&amp;lt;b&amp;gt;literal&amp;lt;/b&amp;gt;'));
});

test('the visible grid uses text rendering independently of the HTML tooltip', () => {
    const blade = readFileSync(new URL('../../resources/views/livewire/epg-viewer.blade.php', import.meta.url), 'utf8');
    assert.ok(blade.includes('x-text="programme.title"'));
    assert.ok(blade.includes('x-tooltip.html="getTooltipContent(programme)"'));
});
