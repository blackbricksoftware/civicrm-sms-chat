// activity_date_time arrives as the site-local "YYYY-MM-DD HH:MM:SS".
export function parseAt(at) {
  return new Date(String(at).replace(' ', 'T'));
}

export function dayKey(at) {
  const d = parseAt(at);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function dayLabel(at) {
  const d = parseAt(at);
  const today = new Date();
  const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
  const same = (a, b) => a.toDateString() === b.toDateString();
  if (same(d, today)) return 'Today';
  if (same(d, yesterday)) return 'Yesterday';
  const opts = { weekday: 'short', month: 'short', day: 'numeric' };
  if (d.getFullYear() !== today.getFullYear()) opts.year = 'numeric';
  return d.toLocaleDateString(undefined, opts);
}

export function timeLabel(at) {
  return parseAt(at).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

export function prettyNumber(n) {
  if (!n) return '';
  const m = /^\+1(\d{3})(\d{3})(\d{4})$/.exec(n);
  return m ? `(${m[1]}) ${m[2]}-${m[3]}` : n;
}
