// Diagnostic: try 5 insert envelope variants, each a tiny distinctively-named frame
const variants = [
  { type: "insert", parent: "2:56", name: "D_A", width: 8, height: 8, fills: [] },
  { type: "insert", parent: "2:56", name: "D_B", node: { type: "FRAME", width: 8, height: 8, fills: [] } },
  { Insert: { parent: "2:56", name: "D_C", width: 8, height: 8, fills: [] } },
  { Insert: { parent: "2:56", name: "D_D", node: { type: "FRAME", width: 8, height: 8, fills: [] } } },
  { type: "insert", parentId: "2:56", name: "D_E", width: 8, height: 8, fills: [] }
];
const jsonl = variants.map(o => JSON.stringify(o)).join("\n");
console.log(JSON.stringify(jsonl));
