// Diagnostic 2: untried envelopes
const v = [
  { operation: "insert", parent: "2:56", name: "D_O1", node: { type: "FRAME", width: 8, height: 8, fills: [] } },
  { op: "insert", parent: "2:56", name: "D_O2", node: { type: "FRAME", width: 8, height: 8, fills: [] } },
  { insert: { parent: "2:56", name: "D_O3", node: { type: "FRAME", width: 8, height: 8, fills: [] } } },
  { create: { parent: "2:56", name: "D_O4", node: { type: "FRAME", width: 8, height: 8, fills: [] } } },
  { type: "insert", parent: "2:56", binding: "D_O5", node: { type: "FRAME", width: 8, height: 8, fills: [] } }
];
const jsonl = v.map(o => JSON.stringify(o)).join("\n");
console.log(JSON.stringify(jsonl));
