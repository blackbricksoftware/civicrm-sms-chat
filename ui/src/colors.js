// Flat UI palette (flatuicolors.com), the members that keep white text
// readable. Lines get colors by their position in context.lines (default
// lines first, then title), so a line keeps its color across visits.
export const PALETTE = [
  // Flat UI (v1)
  '#2980b9', // belize hole
  '#27ae60', // nephritis
  '#8e44ad', // wisteria
  '#d35400', // pumpkin
  '#16a085', // green sea
  '#c0392b', // pomegranate
  '#2c3e50', // midnight blue
  '#f39c12', // orange
  '#3498db', // peter river
  '#2ecc71', // emerald
  '#9b59b6', // amethyst
  '#e67e22', // carrot
  '#1abc9c', // turquoise
  '#e74c3c', // alizarin
  '#34495e', // wet asphalt
  // Flat UI v2 (American / Spanish / Dutch palettes)
  '#6c5ce7', // exodus fruit
  '#0984e3', // electron blue
  '#00b894', // mint leaf
  '#e17055', // orangeville
  '#d63031', // chi-gong
  '#e84393', // prunus avium
  '#00cec9', // robin's egg blue
  '#b53471', // magenta purple
  '#1289a7', // mediterranean sea
  '#009432', // pixelated grass
  '#ee5a24', // puffins bill
  '#0652dd', // merchant marine
  '#5758bb', // circumorbital ring
  '#833471', // hollyhock
  '#006266', // pine glade
];
export const UNKNOWN = '#7f8c8d'; // asbestos

export function lineColors(lines) {
  const map = new Map();
  (lines || []).forEach((l, i) => map.set(l.id, PALETTE[i % PALETTE.length]));
  return map;
}
