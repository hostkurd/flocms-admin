/**
 * YouTube and Vimeo addresses as {provider, id}. Mirrors RichText::embed() in
 * PHP, which checks the saved value again and builds the iframe.
 */
export type Embed = { provider: 'youtube' | 'vimeo'; id: string };

function seconds(value: string | null): number {
  if (!value) return 0;
  if (/^\d{1,6}s?$/.test(value)) return parseInt(value, 10);
  const match = /^(?:(\d{1,2})h)?(?:(\d{1,2})m)?(?:(\d{1,2})s)?$/.exec(value);
  return match ? Number(match[1] ?? 0) * 3600 + Number(match[2] ?? 0) * 60 + Number(match[3] ?? 0) : 0;
}

export function parseEmbed(input: string, providers: string[]): Embed | null {
  let value = input.trim();
  if (value === '' || value.length > 500 || /[\s\\]/.test(value)) return null;
  if (!/^https?:\/\//i.test(value)) value = `https://${value}`;
  let url: URL;
  try { url = new URL(value); } catch { return null; }
  if (url.username || url.password) return null;
  const host = url.hostname.toLowerCase();
  const path = url.pathname;

  if (providers.includes('youtube') && ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'].includes(host)) {
    let id: string | null = null;
    if (host === 'youtu.be') id = path.slice(1);
    else if (path === '/watch') id = url.searchParams.get('v');
    else id = /^\/(?:embed|shorts|live|v)\/([^/]+)\/?$/.exec(path)?.[1] ?? null;
    if (!id || !/^[A-Za-z0-9_-]{11}$/.test(id)) return null;
    const start = seconds(url.searchParams.get('t') ?? url.searchParams.get('start') ?? (url.hash.startsWith('#t=') ? url.hash.slice(3) : null));
    return { provider: 'youtube', id: id + (start > 0 ? `?start=${start}` : '') };
  }
  if (providers.includes('vimeo') && ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(host)) {
    const match = host === 'player.vimeo.com'
      ? /^\/video\/(\d{1,12})\/?$/.exec(path)
      : /^\/(?:channels\/[A-Za-z0-9_-]+\/|groups\/[A-Za-z0-9_-]+\/videos\/)?(\d{1,12})(?:\/([a-f0-9]{6,20}))?\/?$/.exec(path);
    if (!match) return null;
    let hash = match[2] ?? url.searchParams.get('h') ?? '';
    if (!/^[a-f0-9]{6,20}$/.test(hash)) hash = '';
    return { provider: 'vimeo', id: match[1] + (hash ? `?h=${hash}` : '') };
  }
  return null;
}
