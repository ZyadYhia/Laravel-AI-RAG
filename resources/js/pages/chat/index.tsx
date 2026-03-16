import { Head, router } from '@inertiajs/react'
import {
    AlertCircle,
    Bot,
    MessageSquarePlus,
    Send,
    Trash2,
    User,
} from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'

import {
    index as chatIndex,
    show as chatShow,
    store,
} from '@/actions/App/Http/Controllers/ChatController'
import {
    destroy,
    index as fetchConversations,
} from '@/actions/App/Http/Controllers/ConversationController'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import AppLayout from '@/layouts/app-layout'
import type { BreadcrumbItem } from '@/types'

type Message = {
    role: 'user' | 'assistant'
    content: string
}

type Conversation = {
    id: string
    title: string
    updated_at: string
}

type PageProps = {
    hasDocuments: boolean
    conversationId: string | null
    initialMessages: Message[]
    conversationTitle: string | null
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Chat', href: '/chat' },
]

export default function ChatIndex({
    hasDocuments,
    conversationId: initialConversationId,
    initialMessages,
    conversationTitle,
}: PageProps) {
    const [messages, setMessages] = useState<Message[]>(initialMessages ?? [])
    const [input, setInput] = useState('')
    const [loading, setLoading] = useState(false)
    const [conversationId, setConversationId] = useState<string | null>(
        initialConversationId,
    )
    const [conversations, setConversations] = useState<Conversation[]>([])
    const [loadingConversations, setLoadingConversations] = useState(true)
    const messagesEndRef = useRef<HTMLDivElement>(null)
    const inputRef = useRef<HTMLTextAreaElement>(null)

    const loadConversations = useCallback(async () => {
        try {
            const { url, method } = fetchConversations()
            const response = await fetch(url, {
                method,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })

            if (response.ok) {
                setConversations(await response.json())
            }
        } finally {
            setLoadingConversations(false)
        }
    }, [])

    useEffect(() => {
        loadConversations()
    }, [loadConversations])

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
    }, [messages])

    useEffect(() => {
        setMessages(initialMessages ?? [])
        setConversationId(initialConversationId)
    }, [initialConversationId, initialMessages])

    function startNewConversation() {
        router.visit(chatIndex.url())
    }

    function openConversation(id: string) {
        if (id === conversationId) return

        router.visit(chatShow.url(id))
    }

    async function deleteConversation(e: React.MouseEvent, id: string) {
        e.stopPropagation()
        const { url } = destroy(id)
        const response = await fetch(url, {
            method: 'DELETE',
            headers: {
                'X-XSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
            },
            credentials: 'same-origin',
        })

        if (response.ok) {
            setConversations((prev) => prev.filter((c) => c.id !== id))

            if (conversationId === id) {
                router.visit(chatIndex.url())
            }
        }
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault()
        const trimmed = input.trim()

        if (!trimmed || loading) return

        const userMessage: Message = { role: 'user', content: trimmed }
        setMessages((prev) => [...prev, userMessage])
        setInput('')
        setLoading(true)

        try {
            const { url, method } = store()

            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    message: trimmed,
                    conversation_id: conversationId,
                }),
                credentials: 'same-origin',
            })

            if (!response.ok) {
                const errorBody = await response.text()
                console.error('Chat error:', response.status, errorBody)
                setMessages((prev) => [
                    ...prev,
                    {
                        role: 'assistant',
                        content:
                            'Sorry, something went wrong. Please try again.',
                    },
                ])
            } else {
                const data = await response.json()

                setMessages((prev) => [
                    ...prev,
                    {
                        role: 'assistant',
                        content: data.text || 'No response received.',
                    },
                ])

                if (data.conversationId && !conversationId) {
                    setConversationId(data.conversationId)
                    window.history.replaceState(
                        {},
                        '',
                        chatShow.url(data.conversationId),
                    )
                    loadConversations()
                }
            }
        } catch (error) {
            console.error('Chat error:', error)
            setMessages((prev) => [
                ...prev,
                {
                    role: 'assistant',
                    content: 'Sorry, something went wrong. Please try again.',
                },
            ])
        }

        setLoading(false)
        inputRef.current?.focus()
    }

    function getCsrfToken(): string {
        const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)

        return match ? decodeURIComponent(match[1]) : ''
    }

    function handleKeyDown(e: React.KeyboardEvent) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault()
            handleSubmit(e as unknown as FormEvent)
        }
    }

    function formatDate(dateStr: string): string {
        const date = new Date(dateStr)
        const now = new Date()
        const diffMs = now.getTime() - date.getTime()
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

        if (diffDays === 0) return 'Today'

        if (diffDays === 1) return 'Yesterday'

        if (diffDays < 7) return `${diffDays}d ago`

        return date.toLocaleDateString()
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Chat" />
            <div className="relative flex-1">
                <div className="absolute inset-0 flex overflow-hidden">
                    {/* Conversation Sidebar */}
                    <div className="flex w-xs shrink-0 flex-col border-r">
                        <div className="flex items-center justify-between border-b px-3 py-3">
                            <h2 className="text-sm font-semibold">History</h2>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={startNewConversation}
                                title="New conversation"
                            >
                                <MessageSquarePlus className="size-4" />
                            </Button>
                        </div>
                        <ScrollArea className="h-72 flex-1 border">
                            <div className="hover:cursor-pointer">
                                {loadingConversations && (
                                    <div className="space-y-2 p-3">
                                        {[1, 2, 3].map((i) => (
                                            <div
                                                key={i}
                                                className="h-12 animate-pulse rounded-lg bg-muted"
                                            />
                                        ))}
                                    </div>
                                )}
                                {!loadingConversations &&
                                    conversations.length === 0 && (
                                        <div className="p-3 text-center text-xs text-muted-foreground">
                                            No conversations yet
                                        </div>
                                    )}

                                {!loadingConversations &&
                                    conversations.map((conv) => (
                                        <button
                                            key={conv.id}
                                            onClick={() =>
                                                openConversation(conv.id)
                                            }
                                            className={`group flex w-full items-start gap-2 border-b px-3 py-2.5 text-left transition-colors hover:cursor-pointer hover:bg-muted/50 ${
                                                conversationId === conv.id
                                                    ? 'bg-muted'
                                                    : ''
                                            }`}
                                        >
                                            <button
                                                onClick={(e) =>
                                                    deleteConversation(
                                                        e,
                                                        conv.id,
                                                    )
                                                }
                                                className="mt-0.5 shrink-0 rounded p-1 opacity-0 transition-opacity group-hover:opacity-100 hover:cursor-pointer hover:bg-destructive/10 hover:text-destructive"
                                                title="Delete conversation"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </button>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {conv.title}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDate(
                                                        conv.updated_at,
                                                    )}
                                                </p>
                                            </div>
                                        </button>
                                    ))}
                            </div>
                        </ScrollArea>
                    </div>

                    {/* Chat Area */}
                    <div className="flex flex-1 flex-col overflow-hidden">
                        {!hasDocuments && (
                            <div className="mx-4 mt-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                <AlertCircle className="size-4 shrink-0" />
                                No documents uploaded yet. Upload documents
                                first to get contextual answers.
                            </div>
                        )}

                        {conversationTitle && (
                            <div className="border-b px-4 py-2">
                                <h3 className="truncate text-sm font-medium">
                                    {conversationTitle}
                                </h3>
                            </div>
                        )}

                        <ScrollArea className="h-75 flex-1">
                            <div className="p-4">
                                {messages.length === 0 && (
                                    <div className="flex h-full flex-col items-center justify-center gap-4">
                                        <Bot className="size-12 text-muted-foreground" />
                                        <div className="text-center">
                                            <h2 className="text-lg font-semibold">
                                                RAG Assistant
                                            </h2>
                                            <p className="text-sm text-muted-foreground">
                                                Ask questions about your
                                                uploaded documents.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                <div className="mx-auto flex max-w-3xl flex-col gap-4">
                                    {messages.map((msg, i) => (
                                        <div
                                            key={i}
                                            className={`flex gap-3 ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                        >
                                            {msg.role === 'assistant' && (
                                                <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                                    <Bot className="size-4" />
                                                </div>
                                            )}
                                            <div
                                                className={`max-w-[80%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap ${
                                                    msg.role === 'user'
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'bg-muted'
                                                }`}
                                            >
                                                {msg.content}
                                            </div>
                                            {msg.role === 'user' && (
                                                <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary">
                                                    <User className="size-4" />
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                    {loading && (
                                        <div className="flex justify-start gap-3">
                                            <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                                <Bot className="size-4" />
                                            </div>
                                            <div className="rounded-2xl bg-muted px-4 py-2.5">
                                                <span className="inline-flex gap-1">
                                                    <span className="size-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:0ms]" />
                                                    <span className="size-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:150ms]" />
                                                    <span className="size-1.5 animate-bounce rounded-full bg-foreground/40 [animation-delay:300ms]" />
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                    <div ref={messagesEndRef} />
                                </div>
                            </div>
                        </ScrollArea>

                        <div className="border-t p-4">
                            <form
                                onSubmit={handleSubmit}
                                className="mx-auto flex max-w-3xl gap-2"
                            >
                                <textarea
                                    ref={inputRef}
                                    value={input}
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={handleKeyDown}
                                    placeholder="Ask about your documents..."
                                    rows={1}
                                    className="flex-1 resize-none rounded-lg border border-input bg-background px-4 py-2.5 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                    disabled={loading}
                                />
                                <Button
                                    type="submit"
                                    size="icon"
                                    disabled={loading || !input.trim()}
                                >
                                    <Send className="size-4" />
                                </Button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    )
}
