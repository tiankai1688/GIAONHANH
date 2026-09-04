// Try envelope: { "Insert": { parent, name, node } } / { "Delete": { id } }
const gray = { r: 0.42, g: 0.447, b: 0.502 };
const headerBg = { r: 0.98, g: 0.98, b: 0.984 };

function hcell(w, label) {
  return {
    type: "FRAME", name: "HCell", width: w, height: 44,
    layoutMode: "HORIZONTAL", counterAxisAlignItems: "CENTER",
    primaryAxisAlignItems: "MIN", paddingLeft: 12, fills: [],
    children: [{
      type: "TEXT", name: "H", characters: label, fontSize: 12,
      fontName: { family: "Sarasa Gothic SC", style: "Regular" },
      fills: [{ type: "SOLID", color: gray }],
      textAlignHorizontal: "LEFT", textAlignVertical: "CENTER",
      lineHeight: { unit: "AUTO" }
    }]
  };
}

const header = {
  type: "FRAME", name: "THeader", width: 1104, height: 44,
  layoutMode: "HORIZONTAL", itemSpacing: 0, counterAxisAlignItems: "CENTER",
  fills: [{ type: "SOLID", color: headerBg }], cornerRadius: 4,
  children: [
    hcell(230, "商家名称"), hcell(110, "类目"), hcell(170, "联系人"),
    hcell(90, "地区"), hcell(130, "入驻时间"), hcell(120, "状态"), hcell(254, "操作")
  ]
};

const ops = [
  { Delete: { id: "2:58" } },
  { Insert: { parent: "2:56", name: "THeader", node: header } }
];

const jsonl = ops.map(o => JSON.stringify(o)).join("\n");
console.log(JSON.stringify(jsonl));
