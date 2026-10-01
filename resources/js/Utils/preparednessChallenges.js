export const CHALLENGE_ROWS_PER_PAGE = 4;
export const CHALLENGE_PAGE_LIMIT = 50;
const defaults = [
    ["Limited human resources.", "Provide additional staff to augment existing human resources and ensure adequate personnel to support operational and response requirements."],
    ["Limited budget allocation, with only ₱3 million available under the Quick Response Fund (QRF).", "Increase the QRF allocation from ₱3 million to ₱5 million to provide sufficient funding for immediate response requirements and address emerging and concurrent disaster-related needs."],
    ["Request for replenishment amounting to ₱1 million for welfare goods (bottled water).", "Follow up on the downloading of ₱1 million in funds for welfare goods."],
    ["Awaiting approval of the request for adjustment of warehouse resource capacity.", "Follow up on the request for approval of the warehouse resource capacity adjustment."],
  ];
const count = (value, fallback, max) => Number.isInteger(Number(value)) && Number(value) >= 0 ? Math.min(Number(value), max) : fallback;
export function challengePagesFromContent(content = {}) {
  if (content.challenge_page_count == null) return [{
    challenges: defaults.map((row, i) => content['challenge_' + (i + 1)] ?? row[0]),
    recommendations: defaults.map((row, i) => content['recommendation_' + (i + 1)] ?? row[1]),
  }];
  return Array.from({ length: Math.max(1, count(content.challenge_page_count, 1, CHALLENGE_PAGE_LIMIT)) }, (_, page) =>
    Object.fromEntries(['challenges', 'recommendations'].map(column => {
      const prefix = 'challenge_page_' + page + '_' + column;
      return [column, Array.from({ length: count(content[prefix + '_count'], 0, CHALLENGE_ROWS_PER_PAGE) }, (_, row) => content[prefix + '_' + row] ?? '')];
    })));
}
export function challengePagesToContent(pages) {
  const content = { challenge_page_count: String(pages.length) };
  pages.forEach((page, index) => Object.entries(page).forEach(([column, rows]) => {
    const prefix = 'challenge_page_' + index + '_' + column;
    content[prefix + '_count'] = String(rows.length);
    rows.forEach((value, row) => { content[prefix + '_' + row] = value; });
  }));
  return content;
}
