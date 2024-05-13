from fastapi import FastAPI
from pydantic import BaseModel, Field
from rerankers import CohereReranker
from typing import List

app = FastAPI()
reranker = CohereReranker(api_key='n1ytpDT5S9jVqY1abqvqoD6flMgo8M25UJce9fLy')

class Document(BaseModel):
    doc_id: int = Field(..., description="The unique ID of the document")
    text: str = Field(..., description="The text of the document")

class RerankRequest(BaseModel):
    query: str = Field(..., description="The query to rank the documents against")
    documents: List[Document] = Field(..., description="The documents to be reranked")
    top_n: int = Field(20, description="The number of top documents to return")

class RerankResponse(BaseModel):
    reranked_documents: List[Document] = Field(..., description="The reranked documents")

@app.post("/rerank", response_model=RerankResponse)
async def rerank_documents(rerank_request: RerankRequest):
    docs = [doc.text for doc in rerank_request.documents]
    doc_ids = [doc.doc_id for doc in rerank_request.documents]
    reranked_results = reranker.rerank(
        query=rerank_request.query,
        documents=docs,
        top_n=rerank_request.top_n
    )
    reranked_documents = [
        Document(doc_id=doc_id, text=text)
        for doc_id, text in zip(doc_ids, reranked_results)
    ]
    return RerankResponse(reranked_documents=reranked_documents)