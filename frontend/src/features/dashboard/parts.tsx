
/**
 * Where a click on the Dashboard goes. Every link carries the same scope as the number it sits on (date range, test-lead switch), so the list
 * it opens has exactly the rows that were counted.
 */
export function makeLinks(range: { from: string; to: string }, includeTest: boolean, tasksScope: 'all' | 'own' | undefined) {
  const test = includeTest ? '' : '&test=exclude';
  const window = `&from=${range.from}&to=${range.to}`;
  const team = tasksScope === 'all' ? 'tab=team&' : '';
  return {
    leadsAll: `/leads?state=all${test}`,
    leadsOpen: `/leads?${test.slice(1)}`.replace(/\?$/, ''),
    leadsCreated: `/leads?state=all${window}${test}`,
    leadsWon: `/leads?state=all&status=won${window}${test}`,
    leadsWindow: (from: string, to: string) => `/leads?state=all&from=${from}&to=${to}${test}`,
    leadsStage: (id: string) => `/leads?state=all&stage=${id}${test}`,
    leadsSource: (source: string) => `/leads?state=all&source=${source}${window}${test}`,
    leadsUnassigned: `/leads?owner=unassigned${test}`,
    leadsLate: `/leads?sla=breached${test}`,
    leadsFollowup: `/leads?followup=overdue${test}`,
    leadsOwner: (id: string) => `/leads?owner=${id}${test}`,
    dealsSold: `/deals?tab=sold${window}${includeTest ? '' : '&test=exclude'}`,
    dealsSoldWindow: (from: string, to: string) => `/deals?tab=sold&from=${from}&to=${to}${includeTest ? '' : '&test=exclude'}`,
    dealsReserved: '/deals?only=reserved',
    dealsOffers: '/deals?only=offered',
    tasksOpen: team ? '/tasks?tab=team' : '/tasks',
    tasksOverdue: `/tasks?${team}due=overdue`,
    tasksToday: `/tasks?${team}due=today`,
    calendar: '/tasks?view=calendar',
    properties: '/properties',
    propertiesStatus: (s: string) => `/properties?status=${s}`,
  };
}
