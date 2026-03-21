import { Head, router, usePage } from '@inertiajs/react'
import { FileUp, Trash2, Upload } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import {
    store,
    destroy,
    toggleEnabled,
} from '@/actions/App/Http/Controllers/DocumentController'

import { Button } from '@/components/ui/button'
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import AppLayout from '@/layouts/app-layout'
import type { BreadcrumbItem } from '@/types'

type DocumentGroup = {
    source: string
    chunks: number
    is_enabled: boolean
    uploaded_at: string
}

type PageProps = {
    documents: DocumentGroup[]
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Documents', href: '/documents' },
]

export default function DocumentsIndex({ documents }: PageProps) {
    const { flash, props } = usePage()
    const { auth } = props
    let flashData = flash as Record<string, string> | undefined
    const [uploading, setUploading] = useState(false)
    const [dragOver, setDragOver] = useState(false)
    const fileInputRef = useRef<HTMLInputElement>(null)

    function handleUpload(e: FormEvent) {
        e.preventDefault()
        const input = fileInputRef.current

        if (!input?.files?.length) return

        const formData = new FormData()
        Array.from(input.files).forEach((file) => {
            formData.append('files[]', file)
        })

        setUploading(true)
        router.visit(store.url(), {
            method: 'post',
            data: formData,
            forceFormData: true,
            onFinish: () => {
                if (input) input.value = ''
            },
        })
    }

    function handleDelete(source: string) {
        if (!confirm(`Delete all chunks from "${source}"?`)) return

        router.visit(destroy.url(source), {
            method: 'delete',
        })
    }

    function handleDrop(e: React.DragEvent) {
        e.preventDefault()
        setDragOver(false)
        const input = fileInputRef.current

        if (input && e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files
            handleUpload(e as unknown as FormEvent)
        }
    }

    const userId = auth.user.id

    useEffect(() => {
        const channel = window.Echo.private(`user.${userId}`)

        channel.listen('DocumentsEmbedding', () => {
            setUploading(true)
        })

        channel.listen('DocumentsEmbedded', () => {
            router.reload()
            flashData = undefined
            setUploading(false)
        })

        return () => {
            channel.stopListening('DocumentsEmbedding')
            channel.stopListening('DocumentsEmbedded')
        }
    }, [userId])

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Documents" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
                {flashData?.status && (
                    <div className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                        {flashData.status}
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Upload Documents</CardTitle>
                        <CardDescription>
                            Upload text files to embed into your knowledge base.
                            Supported formats: txt, md, csv, json, xml, html,
                            pdf, and more.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleUpload}>
                            <div
                                className={`flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors ${
                                    dragOver
                                        ? 'border-primary bg-primary/5'
                                        : 'border-muted-foreground/25 hover:border-primary/50'
                                }`}
                                onDragOver={(e) => {
                                    e.preventDefault()
                                    setDragOver(true)
                                }}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={handleDrop}
                                onClick={() => fileInputRef.current?.click()}
                            >
                                <Upload className="mb-2 size-8 text-muted-foreground" />
                                <p className="text-sm text-muted-foreground">
                                    Drag & drop files here, or click to select
                                </p>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    multiple
                                    accept=".txt,.md,.csv,.json,.xml,.html,.yml,.yaml,.pdf,.docx,.pages,.php,.js,.ts,.py,.log,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/pdf,application/x-iwork-pages-sffpages"
                                    className="hidden"
                                    onChange={(e) => {
                                        if (e.target.files?.length) {
                                            handleUpload(
                                                e as unknown as FormEvent,
                                            )
                                        }
                                    }}
                                />
                            </div>
                            {uploading && (
                                <div className="mt-3 flex items-center gap-2 text-sm text-muted-foreground">
                                    <div className="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    Embedding documents...
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Knowledge Base</CardTitle>
                        <CardDescription>
                            {documents.length === 0
                                ? 'No documents uploaded yet.'
                                : `${documents.length} document(s) in your knowledge base.`}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {documents.length > 0 && (
                            <div className="divide-y">
                                {documents.map((doc) => (
                                    <div
                                        key={doc.source}
                                        className="flex items-center justify-between py-3"
                                    >
                                        <div className="flex items-center gap-3">
                                            <FileUp className="size-5 text-muted-foreground" />
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {doc.source}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {doc.chunks} chunk(s)
                                                    &middot; {doc.uploaded_at}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Switch
                                                checked={doc.is_enabled}
                                                onCheckedChange={() =>
                                                    router.visit(
                                                        toggleEnabled.url(
                                                            doc.source,
                                                        ),
                                                        { method: 'patch' },
                                                    )
                                                }
                                                size="default"
                                                aria-label="Toggle document"
                                                className="hover:cursor-pointer"
                                            />
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                onClick={() =>
                                                    handleDelete(doc.source)
                                                }
                                            >
                                                <Trash2 className="size-5 text-red-500" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    )
}
