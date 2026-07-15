import { Head } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Database, FileSpreadsheet, PackageCheck, PackagePlus, Truck } from 'lucide-react';
import AppLayout, { Card, DataTable, ExportableCard } from '@/Layouts/AppLayout';

const steps = [
    {
        icon: FileSpreadsheet,
        title: '1. Source Transaction',
        detail: 'Google Sheet rows are treated as source documents. Each row is imported into a staging table with the full raw payload, row number, and hash.',
    },
    {
        icon: Database,
        title: '2. Normalize Master Data',
        detail: 'Warehouse ID and name create or update warehouses. Item category, item name, and UOM create or reuse inventory item records.',
    },
    {
        icon: PackagePlus,
        title: '3. Add Items / Receipts',
        detail: 'Rows with a Receipt quantity increase the matching batch stock. Source of goods, supplier, DR/reference, unit cost, cost, expiry, encoder, and remarks are stored in transaction details.',
    },
    {
        icon: PackageCheck,
        title: '4. Release / Issue Items',
        detail: 'Rows with an Issuance quantity create a release transaction and decrease stock. Recipient, delivery site, expected delivery date, RIS/IF/STF, and transport details are stored with the release.',
    },
    {
        icon: Truck,
        title: '5. Dispatch and Delivery',
        detail: 'Release details feed dispatch planning. Vehicle, driver, land/sea/air transport, contact number, actual delivery, and remarks remain auditable.',
    },
    {
        icon: CheckCircle2,
        title: '6. Monitor and Reconcile',
        detail: 'Available Stock from the sheet is stored as the reported stockpile after the transaction, allowing system stockpile to be compared against spreadsheet stockpile figures.',
    },
];

const mapping = [
    ['Warehouse', 'Warehouse ID, Warehouse Name', 'warehouses.external_warehouse_id, warehouses.name'],
    ['Item master', 'Item Category, Item, Brand/Desc, UOM', 'inventory_items.category, name, description, unit'],
    ['Batch', 'Warehouse ID, Item, Expiry', 'inventory_batches.batch_number, expiration_date'],
    ['Receipt', 'Receipt, Unit Cost, Cost, Source, Supplier, Reference', 'inventory_transactions type=receipt'],
    ['Issuance', 'Issuance, Recipient, Delivery Site, Purpose, RIS/IF/STF', 'inventory_transactions type=release'],
    ['Transport', 'Land/Sea/Air transportation fields, Plate, Driver, Contact Number', 'inventory_transactions.transport_details'],
    ['Audit', 'Encoded By, Edited By, Status, Remarks', 'warehouse_sheet_imports.raw_payload and transaction metadata'],
];

export default function ProcessFlow({ sheet, importSummary, recentImports, recentTransactions }) {
    return (
        <AppLayout title="Inventory Process Flow">
            <Head title="Inventory Process Flow" />

            <div id="process-import-summary" className="grid scroll-mt-28 gap-4 md:grid-cols-4">
                {Object.entries(importSummary).map(([key, value]) => (
                    <Card key={key}>
                        <p className="text-xs uppercase text-slate-500 dark:text-zinc-400">{key.replaceAll('_', ' ')}</p>
                        <p className="mt-2 text-2xl font-bold">{Number(value).toLocaleString()}</p>
                    </Card>
                ))}
            </div>

            <Card id="process-sheet-source" className="mt-6 scroll-mt-28">
                <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 className="font-semibold">{sheet.title}</h2>
                        <p className="text-sm text-slate-500 dark:text-zinc-400">{sheet.worksheet ?? 'Warehouse Inventory'}</p>
                    </div>
                    <a href={sheet.url} target="_blank" rel="noreferrer" className="text-sm font-semibold text-brand-700 dark:text-brand-100">Open Google Sheet</a>
                </div>
            </Card>

            <div id="process-workflow" className="mt-6 grid scroll-mt-28 gap-4 xl:grid-cols-3">
                {steps.map((step, index) => {
                    const Icon = step.icon;
                    return (
                        <Card key={step.title}>
                            <div className="flex items-start gap-3">
                                <Icon className="mt-1 h-5 w-5 shrink-0 text-brand-600" />
                                <div>
                                    <h3 className="font-semibold">{step.title}</h3>
                                    <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-zinc-300">{step.detail}</p>
                                </div>
                            </div>
                            {index < steps.length - 1 && <ArrowRight className="mt-4 hidden h-4 w-4 text-slate-400 xl:block" />}
                        </Card>
                    );
                })}
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <ExportableCard id="process-data-mapping" title="Google Sheet to Database Mapping" className="scroll-mt-28" showExportButtons={false}>
                    <h2 className="mb-4 font-semibold">Google Sheet to Database Mapping</h2>
                    <DataTable columns={['Area', 'Sheet Columns', 'Database Target']} rows={mapping.map((row) => (
                        <tr key={row[0]}>
                            <td className="px-4 py-3 font-medium">{row[0]}</td>
                            <td className="px-4 py-3">{row[1]}</td>
                            <td className="px-4 py-3">{row[2]}</td>
                        </tr>
                    ))} />
                </ExportableCard>

                <ExportableCard id="process-transaction-rules" title="Transaction Rules" className="scroll-mt-28" showExportButtons={false}>
                    <h2 className="mb-4 font-semibold">Transaction Rules</h2>
                    <div className="space-y-4 text-sm leading-6 text-slate-600 dark:text-zinc-300">
                        <p><strong>Add items:</strong> create or select the warehouse, item, UOM, expiry, source of goods, supplier, reference, receipt quantity, unit cost, and remarks. The system records a receipt and increases batch quantity.</p>
                        <p><strong>Release or issue items:</strong> select stock by warehouse, item, and expiry; encode recipient, purpose, RIS/IF/STF, issuance quantity, delivery site, expected delivery date, and transport details. The system records a release and decreases batch quantity.</p>
                        <p><strong>Current stockpile:</strong> imported WIT rows are grouped by warehouse, item, and Brand / Description. Stockpile is computed as Receipt minus Issuance, so variants such as Prepacked, Produced - Vacuum, and Loose Pack remain separate.</p>
                        <p><strong>Transfers:</strong> move stock from one warehouse batch to another with transfer remarks and responsible personnel. The transaction history remains linked to both warehouses.</p>
                        <p><strong>Reconciliation:</strong> compare system batch quantity with the imported Available Stock value. Differences should be handled through an adjustment transaction with approval remarks.</p>
                    </div>
                </ExportableCard>
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-2">
                <ExportableCard id="process-recent-imports" title="Recent Imported Rows" className="scroll-mt-28" showExportButtons={false}>
                    <h2 className="mb-4 font-semibold">Recent Imported Rows</h2>
                    <DataTable columns={['Row', 'Warehouse', 'Item', 'Status']} rows={recentImports.map((row) => (
                        <tr key={row.id}>
                            <td className="px-4 py-3">{row.sheet_row_number}</td>
                            <td className="px-4 py-3">{row.warehouse?.name}</td>
                            <td className="px-4 py-3">{row.item?.name}</td>
                            <td className="px-4 py-3">{row.import_status}</td>
                        </tr>
                    ))} />
                </ExportableCard>

                <ExportableCard id="process-recent-transactions" title="Recent Transactions" className="scroll-mt-28" showExportButtons={false}>
                    <h2 className="mb-4 font-semibold">Recent Transactions</h2>
                    <DataTable columns={['Type', 'Item', 'Warehouse', 'Qty', 'Purpose']} rows={recentTransactions.map((tx) => (
                        <tr key={tx.id}>
                            <td className="px-4 py-3">{tx.type}</td>
                            <td className="px-4 py-3">{tx.batch?.item?.name}</td>
                            <td className="px-4 py-3">{tx.batch?.warehouse?.name}</td>
                            <td className="px-4 py-3">{tx.quantity}</td>
                            <td className="px-4 py-3">{tx.purpose}</td>
                        </tr>
                    ))} />
                </ExportableCard>
            </div>
        </AppLayout>
    );
}
